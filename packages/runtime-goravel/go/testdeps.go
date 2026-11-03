//go:build js

package main

import (
	"errors"
	"io"
	"reflect"
	"time"
)

// testDeps : ce que testing.MainStart attend de son hôte (l'équivalent de testing/internal/testdeps, que seul
// `go test` peut importer). Tout est sélectionné (le filtrage par nom se fait avant), sans profilage ni fuzzing.
type testDeps struct{}

// corpusEntry : le type des entrées de fuzzing de testing (un alias de struct anonyme, qu'on peut donc réécrire).
type corpusEntry = struct {
	Parent     string
	Path       string
	Data       []byte
	Values     []any
	Generation int
	IsSeed     bool
}

var errNoFuzz = errors.New("non disponible dans le navigateur")

func (testDeps) MatchString(string, string) (bool, error)    { return true, nil }
func (testDeps) StartCPUProfile(io.Writer) error             { return errNoFuzz }
func (testDeps) StopCPUProfile()                             {}
func (testDeps) WriteProfileTo(string, io.Writer, int) error { return errNoFuzz }
func (testDeps) ImportPath() string                          { return "goravel" }
func (testDeps) StartTestLog(io.Writer)                      {}
func (testDeps) StopTestLog() error                          { return nil }
func (testDeps) SetPanicOnExit0(bool)                        {}
func (testDeps) CoordinateFuzzing(time.Duration, int64, time.Duration, int64, int, []corpusEntry, []reflect.Type, string, string) error {
	return errNoFuzz
}
func (testDeps) RunFuzzWorker(func(corpusEntry) error) error { return errNoFuzz }
func (testDeps) ReadCorpus(string, []reflect.Type) ([]corpusEntry, error) {
	return nil, errNoFuzz
}
func (testDeps) CheckCorpus([]any, []reflect.Type) error { return nil }
func (testDeps) ResetCoverage()                          {}
func (testDeps) SnapshotCoverage()                       {}
func (testDeps) InitRuntimeCoverage() (mode string, tearDown func(string, string) (string, error), snapcov func() float64) {
	return
}
