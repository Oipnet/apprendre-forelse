//go:build js || wasip1

package clipboard

import "errors"

func readAll() (string, error) { return "", errors.New("clipboard indisponible") }
func writeAll(string) error    { return errors.New("clipboard indisponible") }
