#!/usr/bin/env bash
# SimpleXboard's fixed-release node installer and systemd maintenance entry point.
# There are deliberately no environment, URL, checksum, or filesystem overrides.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

readonly VERSION='v1.13-simplexboard.2'
readonly RELEASE_TAG='node-v1.13-simplexboard.2'
readonly RELEASE_BASE='https://github.com/CoderDoubleflower/SimpleXboard/releases/download/node-v1.13-simplexboard.2'
readonly NODE_BINARY='/usr/local/bin/xboard-node'
readonly CLI_BINARY='/usr/local/bin/xbctl'
readonly CLI_LINK='/usr/bin/xbctl'
readonly CONFIG_ROOT='/etc/xboard-node'
readonly CONFIG_FILE="${CONFIG_ROOT}/config.yml"
readonly UNIT_FILE='/etc/systemd/system/xboard-node.service'
readonly SERVICE='xboard-node.service'
readonly BACKUP_ROOT='/var/backups/simplexboard-node'
readonly LOCK_ROOT='/run/simplexboard-node-maintenance'
readonly SYSTEMD_RUNTIME='/run/systemd/system'
readonly TEMP_TEMPLATE='/tmp/simplexboard-node.XXXXXXXX'
readonly HEALTH_ATTEMPTS=30
readonly HEALTH_DELAY=1

ACTION=''
PURGE=0
ARCH=''
STATE=''
TEMP=''
BACKUP=''
STAGED=''
HEALTH_PORT=0
TRANSACTION=''
COMMITTED=0
INSTALL_ARGS=()

info() { printf '[SimpleXboard] %s\n' "$*"; }
die() { printf '[SimpleXboard] 错误：%s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'HELP'
用法：node-manager.sh install [安装参数]
      node-manager.sh upgrade
      node-manager.sh uninstall [--purge]

install   首次安装；已有标准安装则保留配置升级；卸载后保留的配置可直接恢复。
upgrade   升级这台 VPS 的整个 xboard-node 服务，保留全部实例、配置、凭据和机器绑定。
uninstall 停止并删除这台 VPS 的整个节点服务及全部实例的运行程序；默认保留配置和备份。
--purge   仅用于 uninstall，额外删除 /etc/xboard-node 的配置、凭据和本地运行数据。

支持 Linux amd64/arm64、标准 systemd 安装。Docker 和自定义启动路径请单独维护。
重启会中断已有连接。任何动作都不会删除面板中的机器、节点或出站记录。

首次安装参数：--mode node|machine --panel URL --token TOKEN
              --node-id ID | --machine-id ID
可选：--node-type TYPE --kernel singbox|xray --health-port PORT
      --gomemlimit VALUE --gogc VALUE
HELP
}

parse_args() {
    if [[ $# -eq 0 ]]; then usage; exit 1; fi
    ACTION="$1"; shift
    case "$ACTION" in help|--help|-h) usage; exit 0;; install|upgrade|uninstall) ;; *) die '未知动作。请使用 install、upgrade 或 uninstall。';; esac
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --purge)
                [[ "$ACTION" == uninstall ]] || die '--purge 只能用于 uninstall。'
                PURGE=1; shift
                ;;
            --mode|--panel|--token|--node-id|--machine-id|--node-type|--kernel|--health-port|--gomemlimit|--gogc)
                [[ "$ACTION" == install && $# -ge 2 && -n "$2" ]] || die '安装参数缺少值或用于了错误的动作。'
                INSTALL_ARGS+=("$1" "$2"); shift 2
                ;;
            *) die '不支持此参数。下载来源和发布版本由维护脚本固定。';;
        esac
    done
}

check_platform() {
    [[ "$(uname -s)" == Linux ]] || die '仅支持 Linux systemd 主机。'
    case "$(uname -m)" in x86_64|amd64) ARCH=amd64;; aarch64|arm64) ARCH=arm64;; *) die '不支持当前架构；仅提供 Linux amd64 和 arm64。';; esac
    command -v systemctl >/dev/null || die '未检测到 systemd。Docker 安装请通过容器镜像维护。'
    [[ -d "$SYSTEMD_RUNTIME" ]] || die 'systemd 未运行。Docker 安装请通过容器镜像维护。'
    if command -v systemd-detect-virt >/dev/null && systemd-detect-virt --quiet --container; then
        die '不支持在容器内修改节点服务；请更新或删除对应容器。'
    fi
}

check_docker_node() {
    local containers
    if command -v docker >/dev/null; then
        containers="$(docker ps --no-trunc --format '{{.Image}} {{.Names}} {{.Command}}' 2>/dev/null || true)"
        if [[ "$containers" == *xboard-node* || "$containers" == *xboard_node* ]]; then
            die '检测到运行中的 xboard-node Docker 容器；请通过容器方式维护，避免同时修改主机服务。'
        fi
    fi
}

check_paths() {
    local path
    for path in "$NODE_BINARY" "$CLI_BINARY" "$CONFIG_ROOT" "$CONFIG_FILE" "$UNIT_FILE" "$BACKUP_ROOT" "$LOCK_ROOT"; do
        [[ ! -L "$path" ]] || die '发现关键路径是符号链接；请手动维护此自定义安装。'
    done
}

inspect_installation() {
    local loaded fragment command_line banner
    loaded="$(systemctl show "$SERVICE" --property=LoadState --value 2>/dev/null || true)"
    if [[ "$loaded" == loaded ]]; then
        fragment="$(systemctl show "$SERVICE" --property=FragmentPath --value)"
        command_line="$(systemctl show "$SERVICE" --property=ExecStart --value)"
        [[ "$fragment" == "$UNIT_FILE" && -f "$UNIT_FILE" ]] || die '服务文件不是标准安装路径；拒绝修改自定义服务。'
        [[ "$command_line" == "{ path=$NODE_BINARY ; argv[]=$NODE_BINARY -c $CONFIG_FILE ; "* && "$command_line" != *'} {'* ]] || die '服务 ExecStart 不是标准 xboard-node 启动命令；拒绝修改。'
        [[ -x "$NODE_BINARY" && -f "$CONFIG_FILE" ]] || die '标准服务缺少二进制或配置；请先修复不完整的安装。'
        banner="$("$NODE_BINARY" -v 2>/dev/null)" || die '现有二进制无法验证为 xboard-node。'
        [[ "$banner" == 'xboard-node '* ]] || die '该路径中的程序不是 xboard-node；拒绝替换或删除。'
        STATE=installed
    elif [[ "$loaded" == not-found || -z "$loaded" ]]; then
        [[ ! -e "$NODE_BINARY" && ! -e "$UNIT_FILE" && ! -e "$CLI_BINARY" ]] || die '发现缺少标准 systemd 服务的残留程序；请手动确认后维护。'
        if [[ -f "$CONFIG_FILE" ]]; then
            STATE=retained
        elif [[ -e "$CONFIG_ROOT" ]]; then
            die '配置目录已存在但缺少 config.yml；拒绝覆盖未知文件。'
        else
            STATE=fresh
        fi
    else
        die 'systemd 服务状态无法确认；未修改任何程序或配置。'
    fi
    if [[ -e "$CLI_BINARY" ]]; then
        banner="$("$CLI_BINARY" version 2>/dev/null)" || die '现有 xbctl 无法验证；拒绝删除或覆盖未知程序。'
        [[ "$banner" == 'xbctl '* ]] || die 'xbctl 路径中的程序不属于标准节点安装。'
    fi
}

download() {
    local asset="$1"
    curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        --retry 3 --connect-timeout 15 --max-time 180 \
        --output "$TEMP/$asset" "$RELEASE_BASE/$asset" || die '发布文件下载失败；运行中的节点未被替换。'
}

verified_asset() {
    local asset="$1" digest
    download "$asset"
    digest="$(awk -v name="$asset" '
        $2 == name || $2 == "*" name {
            if (length($1) != 64 || $1 !~ /^[0-9a-fA-F]+$/) exit 2;
            count++; hash = $1
        }
        END { if (count != 1) exit 3; print hash }
    ' "$TEMP/SHA256SUMS")" || die '发布校验清单缺少唯一有效的文件散列。'
    (cd "$TEMP" && printf '%s  %s\n' "$digest" "$asset" | sha256sum --check --status) || die 'SHA-256 校验失败；运行中的节点未被替换。'
}

prepare_release() {
    local banner
    command -v curl >/dev/null || die '请先安装 curl。'
    command -v sha256sum >/dev/null || die '请先安装提供 sha256sum 的 coreutils。'
    TEMP="$(mktemp -d "$TEMP_TEMPLATE")"
    download SHA256SUMS
    verified_asset "xboard-node-linux-$ARCH"
    verified_asset "xbctl-linux-$ARCH"
    chmod 755 "$TEMP/xboard-node-linux-$ARCH" "$TEMP/xbctl-linux-$ARCH"
    banner="$("$TEMP/xboard-node-linux-$ARCH" -v 2>/dev/null)" || die '下载的节点程序无法在本机运行。'
    [[ "$banner" == "xboard-node $VERSION (built "*')' ]] || die '下载的节点程序版本与固定发布版本不一致。'
    banner="$("$TEMP/xbctl-linux-$ARCH" version 2>/dev/null)" || die '下载的管理工具无法在本机运行。'
    [[ "$banner" == 'xbctl '* ]] || die '下载的管理工具身份不正确。'
}

read_health_port() {
    local value
    value="$("$TEMP/xbctl-linux-$ARCH" config health-port --config "$CONFIG_FILE" 2>/dev/null)" || die '无法解析现有配置的健康检查端口；未替换程序。'
    [[ "$value" =~ ^[0-9]{1,5}$ ]] || die '健康检查端口不是有效整数。'
    HEALTH_PORT=$((10#$value))
    (( HEALTH_PORT <= 65535 )) || die '健康检查端口超出有效范围。'
}

backup_state() {
    install -d -m 700 "$BACKUP_ROOT"
    BACKUP="$(mktemp -d "$BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
    [[ ! -e "$NODE_BINARY" ]] || cp -a -- "$NODE_BINARY" "$BACKUP/xboard-node"
    [[ ! -e "$CONFIG_ROOT" ]] || cp -a -- "$CONFIG_ROOT" "$BACKUP/config"
    [[ ! -e "$UNIT_FILE" ]] || cp -a -- "$UNIT_FILE" "$BACKUP/xboard-node.service"
    [[ ! -e "$CLI_BINARY" ]] || cp -a -- "$CLI_BINARY" "$BACKUP/xbctl"
    info "备份已保存到 $BACKUP；包含配置和凭据，请勿公开。"
}

wait_healthy() {
    local attempt stable=0
    for ((attempt=0; attempt<HEALTH_ATTEMPTS; attempt++)); do
        if systemctl is-active --quiet "$SERVICE"; then
            if (( HEALTH_PORT == 0 )) || curl --fail --silent --max-time 2 --noproxy '*' \
                "http://127.0.0.1:$HEALTH_PORT/healthz" >/dev/null 2>&1; then
                stable=$((stable + 1))
                # Do not mistake systemd's brief active state before a crash for success.
                (( stable < 3 )) || return 0
            else stable=0
            fi
        else stable=0
        fi
        sleep "$HEALTH_DELAY"
    done
    return 1
}

atomic_node_install() {
    STAGED="$(mktemp "${NODE_BINARY}.new.XXXXXXXX")"
    install -m 755 "$TEMP/xboard-node-linux-$ARCH" "$STAGED"
    TRANSACTION=upgrade
    mv -f -- "$STAGED" "$NODE_BINARY"
    STAGED=''
}

rollback() {
    local restore
    info '升级未通过启动检查，正在恢复原节点程序。'
    if [[ "$TRANSACTION" == upgrade ]]; then
        restore="$(mktemp "${NODE_BINARY}.rollback.XXXXXXXX")" || return 1
        if ! cp -a -- "$BACKUP/xboard-node" "$restore" || ! mv -f -- "$restore" "$NODE_BINARY"; then
            rm -f -- "$restore"
            return 1
        fi
        systemctl restart "$SERVICE" || return 1
        wait_healthy || return 1
        info '已恢复原节点程序并通过启动检查；现有配置保持原样。'
    elif [[ "$TRANSACTION" == restore ]]; then
        # A failed copy/unit write can leave no loadable service at all. Confirm
        # it is stopped, then remove only the files created by this transaction.
        if ! systemctl stop "$SERVICE" >/dev/null 2>&1 && systemctl is-active --quiet "$SERVICE"; then return 1; fi
        systemctl disable "$SERVICE" >/dev/null 2>&1 || true
        if systemctl is-active --quiet "$SERVICE"; then return 1; fi
        rm -f -- "$NODE_BINARY" "$CLI_BINARY" "$UNIT_FILE"
        if [[ -L "$CLI_LINK" && "$(readlink "$CLI_LINK")" == "$CLI_BINARY" ]]; then rm -f -- "$CLI_LINK"; fi
        systemctl daemon-reload || return 1
        info '恢复安装失败，已撤销新服务和程序；原配置仍保留。'
    fi
}

cleanup() {
    local status=$?
    trap - EXIT INT TERM HUP
    set +e
    if [[ -n "$TRANSACTION" && "$COMMITTED" -eq 0 ]]; then
        if ! rollback; then
            printf '[SimpleXboard] 自动恢复未完成。请使用 %s 内的备份人工恢复，并检查 systemd 日志。\n' "$BACKUP" >&2
        fi
        status=1
    fi
    [[ -z "$STAGED" ]] || rm -f -- "$STAGED"
    [[ -z "$TEMP" ]] || rm -rf -- "$TEMP"
    exit "$status"
}

upgrade_node() {
    prepare_release
    read_health_port
    backup_state
    atomic_node_install
    info '正在重启整个节点服务，已有连接将中断。'
    systemctl restart "$SERVICE" || die '新程序启动失败。'
    wait_healthy || die '新程序未通过健康检查。'
    COMMITTED=1
    info "已升级到 $VERSION。配置、凭据、机器绑定、systemd unit 和本机 xbctl 均保留。"
}

restore_retained() {
    prepare_release
    read_health_port
    backup_state
    info '发现先前保留的配置；将恢复全部原实例，本次命令中的新绑定参数不会覆盖它们。'
    TRANSACTION=restore
    install -m 755 "$TEMP/xboard-node-linux-$ARCH" "$NODE_BINARY"
    install -m 755 "$TEMP/xbctl-linux-$ARCH" "$CLI_BINARY"
    if [[ ! -e "$CLI_LINK" && ! -L "$CLI_LINK" ]]; then ln -s "$CLI_BINARY" "$CLI_LINK"; fi
    cat > "$UNIT_FILE" <<EOF
[Unit]
Description=Xboard Node Backend
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$CONFIG_ROOT
EnvironmentFile=-$CONFIG_ROOT/credentials.env
ExecStart=$NODE_BINARY -c $CONFIG_FILE
Restart=always
RestartSec=5
LimitNOFILE=1048576
NoNewPrivileges=true
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF
    chmod 644 "$UNIT_FILE"
    systemctl daemon-reload
    systemctl enable "$SERVICE" >/dev/null
    systemctl start "$SERVICE"
    wait_healthy || die '恢复后的服务未通过健康检查。'
    COMMITTED=1
    info "已恢复原配置并安装 $VERSION。"
}

install_node() {
    if [[ "$STATE" == installed ]]; then
        info '已存在标准安装，将保留全部原实例和绑定，仅升级程序；不会应用新绑定参数。'
        upgrade_node
    elif [[ "$STATE" == retained ]]; then
        restore_retained
    else
        [[ ${#INSTALL_ARGS[@]} -gt 0 ]] || die '首次安装需要面板地址、令牌和节点或机器 ID。'
        prepare_release
        verified_asset upstream-install.sh
        info "正在安装固定版本 $VERSION。"
        bash "$TEMP/upstream-install.sh" install "${INSTALL_ARGS[@]}" \
            --binary "$TEMP/xboard-node-linux-$ARCH" --xbctl-binary "$TEMP/xbctl-linux-$ARCH"
        read_health_port
        wait_healthy || die '安装完成但启动检查未通过，请查看节点日志。'
        info "已安装 $VERSION；没有从上游 latest 下载节点程序。"
    fi
}

uninstall_node() {
    if [[ "$STATE" == fresh ]]; then info '未发现已安装的标准节点服务。'; return; fi
    backup_state
    if [[ "$STATE" == installed ]]; then
        info '将停止并删除这台 VPS 的整个节点服务，包括该服务管理的全部实例。'
        systemctl disable --now "$SERVICE" || die '无法停止或禁用服务，未删除程序和配置。'
        if systemctl is-active --quiet "$SERVICE"; then die '服务仍在运行，未删除程序和配置。'; fi
        rm -f -- "$NODE_BINARY" "$CLI_BINARY" "$UNIT_FILE"
        if [[ -L "$CLI_LINK" && "$(readlink "$CLI_LINK")" == "$CLI_BINARY" ]]; then rm -f -- "$CLI_LINK"; fi
        systemctl daemon-reload
        systemctl reset-failed "$SERVICE" >/dev/null 2>&1 || true
    fi
    if (( PURGE )); then
        [[ "$CONFIG_ROOT" == /etc/xboard-node && ! -L "$CONFIG_ROOT" ]] || die '配置删除路径检查失败。'
        rm -rf -- "$CONFIG_ROOT"
        info '已删除节点服务和 /etc/xboard-node；独立备份和面板记录仍保留。'
    else
        info '节点服务已删除，/etc/xboard-node 配置、凭据和独立备份保留；面板记录未改变。'
    fi
}

main() {
    parse_args "$@"
    check_platform
    if [[ "$(id -u)" -ne 0 ]]; then
        command -v sudo >/dev/null || die '请用 root 运行，或先安装 sudo。'
        [[ -f "$0" ]] || die '请先将脚本保存为文件，再用 sudo bash 运行。'
        exec sudo -- bash "$0" "$@"
    fi
    check_paths
    check_docker_node
    command -v flock >/dev/null || die '请先安装提供 flock 的 util-linux。'
    install -d -m 700 "$LOCK_ROOT"
    exec 9>"$LOCK_ROOT/lock"
    flock --nonblock 9 || die '另一个节点维护任务正在运行，请稍后重试。'
    inspect_installation
    trap cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    trap 'exit 129' HUP
    case "$ACTION" in
        install) install_node;;
        upgrade) [[ "$STATE" == installed ]] || die '未找到完整标准安装；请使用 install 恢复或安装。'; upgrade_node;;
        uninstall) uninstall_node;;
    esac
}

main "$@"
