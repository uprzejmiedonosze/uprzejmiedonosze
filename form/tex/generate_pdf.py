#!/usr/bin/env python3
# -*- coding: utf-8 -*-
from pprint import pprint
import argparse
import json
import os
import subprocess
from string import Template

APP_DIR = 'app/'

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("file", help="json file")
    args = parser.parse_args()

    jsonfile = args.file
    texfile = jsonfile.replace('.json', '.tex')
    data = json.loads(open(jsonfile, encoding='utf-8').read())

    filein = open('template.tpl', encoding='utf-8')
    src = Template(filein.read())
    tex = src.substitute(data)

    with open(texfile,'w') as f:
        f.write(tex)

    #proc = subprocess.Popen(['pdflatex', texfile])

    cmd = ['pdflatex', '-interaction', 'nonstopmode', texfile.replace(APP_DIR, '')]
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, cwd=APP_DIR)
    proc.communicate(timeout=5)


    retcode = proc.returncode
    if not retcode == 0:
        raise ValueError('Error {} executing command: {}'.format(retcode, ' '.join(cmd)))

    os.unlink(texfile)
    os.unlink(jsonfile.replace('.json', '.out'))
    os.unlink(jsonfile.replace('.json', '.aux'))
    os.unlink(jsonfile.replace('.json', '.log'))

if __name__ == '__main__':
    main()
