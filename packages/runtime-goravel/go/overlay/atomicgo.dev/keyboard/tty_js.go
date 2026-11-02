//go:build js || wasip1

package keyboard

import (
	"errors"
	"os"
)

func restoreInput() error             { return nil }
func initInput() error                { return errors.New("pas de terminal") }
func openInputTTY() (*os.File, error) { return nil, errors.New("pas de terminal") }

func closeInput() error { return nil }
