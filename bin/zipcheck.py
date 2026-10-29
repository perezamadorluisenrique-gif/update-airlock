# Checks a release zip: one top folder named like the slug, forward slashes, deflate or store,
# no nested zips, no dev files, intact, under 10 MB.
# Usage: python -I zipcheck.py <zip> <slug>
import os
import sys
import zipfile

zp, slug = sys.argv[1], sys.argv[2]
z = zipfile.ZipFile(zp)
names = z.namelist()
tops = {n.split('/')[0] for n in names}
ok = True


def bad(msg):
    global ok
    print('FAIL:', msg)
    ok = False


if tops != {slug}:
    bad(f'top folders {tops} != {{{slug}}}')
if any(chr(92) in n for n in names):
    bad('backslashes in entry names')
if any(n.endswith('.zip') for n in names):
    bad('nested zip')
if any(i.compress_type not in (0, 8) for i in z.infolist()):
    bad('non-standard compression')
if z.testzip() is not None:
    bad('corrupt entry')
DEV = ('fixture', '/tests/', '/bin/', '/vendor/', '/node_modules/', 'phpcs', 'phpunit',
       'composer.', 'package.json', '/.git', '.py', '.log', '/languages/', '.po', '.mo', '.pot')
for n in names:
    low = n.lower()
    base = os.path.basename(n)
    if any(d in low for d in DEV) or (base.startswith('.') and base != '.htaccess'):
        bad(f'dev file in zip: {n}')
size = os.path.getsize(zp)
if size > 10 * 1024 * 1024:
    bad(f'zip is {size} bytes (over 10 MB)')
print(f'zip: {len(names)} entries, top={tops}, {size} bytes, ok={ok}')
sys.exit(0 if ok else 1)
