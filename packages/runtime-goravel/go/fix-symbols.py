"""Corrige les fichiers de yaegi extract qui importent deux paquets nommés http (voir gen-symbols.sh).

yaegi extract écrit `http.X` aussi bien pour un paquet http de Goravel (contracts/http, contracts/testing/http)
que pour net/http. On renomme l'import de net/http en nethttp, puis chaque `http.X` qui n'est pas un symbole
du paquet http de Goravel importé par le fichier passe par nethttp. Les symboles d'un paquet se lisent dans
le fichier extrait pour lui (les clés de sa table Symbols) : les deux paquets doivent être extraits.
"""
import glob, os, re, sys

os.chdir(sys.argv[1])
exported = {}
for path in glob.glob('*.go'):
    src = open(path).read()
    head = re.search(r"yaegi extract (\S+)'", src)
    if head:
        exported[head.group(1)] = set(re.findall(r'^\t\t"([A-Z]\w*)":', src, flags=re.M))

for path in glob.glob('*.go'):
    src = open(path).read()
    if '\t"net/http"\n' not in src:
        continue
    goravel = re.search(r'^\t"(github\.com/goravel/framework/\S*/http)"$', src, flags=re.M)
    if not goravel:
        continue
    own = exported.get(goravel.group(1))
    if own is None:
        sys.exit(f'{path} : extraire aussi {goravel.group(1)}')
    src = src.replace('\t"net/http"\n', '\tnethttp "net/http"\n')
    src = re.sub(r'\bhttp\.([A-Z]\w*)', lambda m: m.group(0) if m.group(1) in own else 'nethttp.' + m.group(1), src)
    # Wrapper d'une méthode qui rend une interface : yaegi écrit "" au lieu de nil quand elle manque.
    src = re.sub(r'(\) String\([^)]*\) http\.AbortableResponse \{\n\tif W\.WString == nil \{\n\t\treturn )""', r'\1nil', src)
    open(path, 'w').write(src)
