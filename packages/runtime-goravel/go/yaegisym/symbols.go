// Package yaegisym : les symboles des paquets compilés, exposés au code interprété (voir gen-symbols.sh).
package yaegisym

import "reflect"

// Symbols : chemin d'import/nom du paquet => symboles, au format de yaegi (interp.Use).
var Symbols = map[string]map[string]reflect.Value{}
