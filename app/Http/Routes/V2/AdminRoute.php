<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\SecurityController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Controllers\V2\Admin\StatController;
use App\Http\Controllers\V2\Admin\SystemController;
use App\Http\Controllers\V2\Admin\BackupController;
use App\Http\Controllers\V2\Admin\TrafficResetController;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => '{admin_path}',
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            $router->get('auth/me', [SecurityController::class, 'me']);
            $router->get('auth/sessions', [SecurityController::class, 'sessions']);
            $router->post('auth/logout', [SecurityController::class, 'logout']);
            $router->post('auth/password', [SecurityController::class, 'password']);
            $router->post('auth/revokeSessions', [SecurityController::class, 'revokeSessions']);
            $router->get('config/fetch', [ConfigController::class, 'fetch']);
            $router->post('config/save', [ConfigController::class, 'save']);
            $router->get('server/route/fetch', [RouteController::class, 'fetch']);
            $router->post('server/route/save', [RouteController::class, 'save']);
            $router->post('server/route/drop', [RouteController::class, 'drop']);
            $router->get('server/manage/getNodes', [ManageController::class, 'getNodes']);
            $router->get('server/manage/generateEchKey', [ManageController::class, 'generateEchKey']);
            $router->post('server/manage/update', [ManageController::class, 'update']);
            $router->post('server/manage/save', [ManageController::class, 'save']);
            $router->post('server/manage/drop', [ManageController::class, 'drop']);
            $router->post('server/manage/copy', [ManageController::class, 'copy']);
            $router->post('server/manage/sort', [ManageController::class, 'sort']);
            $router->post('server/manage/batchDelete', [ManageController::class, 'batchDelete']);
            $router->post('server/manage/batchUpdate', [ManageController::class, 'batchUpdate']);
            $router->post('server/manage/resetTraffic', [ManageController::class, 'resetTraffic']);
            $router->post('server/manage/batchResetTraffic', [ManageController::class, 'batchResetTraffic']);
            $router->get('server/machine/fetch', [MachineController::class, 'fetch']);
            $router->get('server/machine/getToken', [MachineController::class, 'getToken']);
            $router->get('server/machine/installCommand', [MachineController::class, 'installCommand']);
            $router->get('server/machine/nodes', [MachineController::class, 'nodes']);
            $router->get('server/machine/history', [MachineController::class, 'history']);
            $router->post('server/machine/save', [MachineController::class, 'save']);
            $router->post('server/machine/drop', [MachineController::class, 'drop']);
            $router->post('server/machine/resetToken', [MachineController::class, 'resetToken']);
            $router->get('user/fetch', [UserController::class, 'fetch']);
            $router->get('user/getUserInfoById', [UserController::class, 'getUserInfoById']);
            $router->post('user/create', [UserController::class, 'create']);
            $router->post('user/update', [UserController::class, 'update']);
            $router->post('user/dumpCSV', [UserController::class, 'dumpCSV']);
            $router->post('user/ban', [UserController::class, 'ban']);
            $router->post('user/resetSecret', [UserController::class, 'resetSecret']);
            $router->post('user/destroy', [UserController::class, 'destroy']);
            $router->get('stat/getStats', [StatController::class, 'getStats']);
            $router->get('stat/getServerLastRank', [StatController::class, 'getServerLastRank']);
            $router->get('stat/getServerYesterdayRank', [StatController::class, 'getServerYesterdayRank']);
            $router->get('stat/getStatUser', [StatController::class, 'getStatUser']);
            $router->get('stat/getStatRecord', [StatController::class, 'getStatRecord']);
            $router->get('stat/getRanking', [StatController::class, 'getRanking']);
            $router->get('stat/getTrafficRank', [StatController::class, 'getTrafficRank']);
            $router->get('system/getSystemStatus', [SystemController::class, 'getSystemStatus']);
            $router->get('system/getQueueStats', [SystemController::class, 'getQueueStats']);
            $router->get('system/getQueueWorkload', [SystemController::class, 'getQueueWorkload']);
            $router->get('system/getAuditLog', [SystemController::class, 'getAuditLog']);
            $router->get('system/getHorizonFailedJobs', [SystemController::class, 'getHorizonFailedJobs']);
            $router->get('backup/list', [BackupController::class, 'list']);
            $router->get('backup/download', [BackupController::class, 'download']);
            $router->post('backup/create', [BackupController::class, 'create']);
            $router->get('traffic-reset/logs', [TrafficResetController::class, 'logs']);
            $router->get('traffic-reset/stats', [TrafficResetController::class, 'stats']);
            $router->get('traffic-reset/user/{userId}/history', [TrafficResetController::class, 'userHistory']);
            $router->post('traffic-reset/reset-user', [TrafficResetController::class, 'resetUser']);
        });
    }
}
