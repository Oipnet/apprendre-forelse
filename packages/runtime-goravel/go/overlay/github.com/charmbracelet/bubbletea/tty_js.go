//go:build js || wasip1

package tea

import (
	"errors"
	"os"
)

func (p *Program) listenForResize(done chan struct{}) { close(done) }
func (p *Program) initInput() error                   { return nil }
func openInputTTY() (*os.File, error)                 { return nil, errors.New("pas de terminal") }

const suspendSupported = false

func suspendProcess() {}
