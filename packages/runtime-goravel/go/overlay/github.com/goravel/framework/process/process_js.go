//go:build js || wasip1

package process

import (
	"os"
	"os/exec"
	"time"

	"github.com/goravel/framework/errors"
)

func setSysProcAttr(cmd *exec.Cmd)              {}
func running(p *os.Process) bool                { return false }
func kill(p *os.Process) error                  { return errors.ProcessNotStarted }
func signal(p *os.Process, sig os.Signal) error { return errors.ProcessNotStarted }
func stop(p *os.Process, done <-chan struct{}, timeout time.Duration, sig ...os.Signal) error {
	return nil
}
