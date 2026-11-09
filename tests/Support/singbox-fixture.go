// Build from the pinned xboard-node module so fixtures use its exact core:
// go build -tags "$NODE_BUILD_TAGS" -o /tmp/singbox-fixture ../tests/Support/singbox-fixture.go
package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"

	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/include"
	"github.com/sagernet/sing-box/option"
	singJSON "github.com/sagernet/sing/common/json"
)

func fail(phase string) {
	// Do not echo configuration, credentials, or decoder input into CI logs.
	fmt.Fprintln(os.Stderr, "sing-box fixture failed during "+phase)
	os.Exit(1)
}

func main() {
	path := flag.String("c", "", "private JSON configuration file")
	check := flag.Bool("check", false, "validate and construct without starting listeners")
	flag.Parse()
	data, err := os.ReadFile(*path)
	if err != nil {
		fail("configuration read")
	}
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()
	ctx = include.Context(ctx)
	opts, err := singJSON.UnmarshalExtendedContext[option.Options](ctx, data)
	if err != nil {
		fail("strict configuration decode")
	}
	instance, err := box.New(box.Options{Context: ctx, Options: opts})
	if err != nil {
		fail("core construction")
	}
	defer instance.Close()
	if *check {
		fmt.Println("fixture-checked")
		return
	}
	if err := instance.Start(); err != nil {
		fail("core startup")
	}
	fmt.Println("fixture-ready")
	<-ctx.Done()
}
