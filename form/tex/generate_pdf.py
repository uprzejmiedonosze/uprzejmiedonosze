#!/usr/bin/env python3
# -*- coding: utf-8 -*-
from pprint import pprint
import argparse
import json
import os
import subprocess
from string import Template

APP_DIR = 'app/'

CATEGORIES = {
    4  : u"Pojazd zastawiał chodnik (mniej niż 1.5m).",
    2  : u"Pojazd znajdował się mniej niż 15 m od przystanku.",
    3  : u"Pojazd znajdował się mniej niż 10m od skrzyżowania.",
    9  : u"Pojazd blokował ścieżkę rowerową",
    5  : u"Pojazd znajdował się mniej niż 10m od przejścia dla pieszych.",
    6  : u"Pojazd był zaparkowany na trawniku/w parku.",
    10 : u"Pojazd znajdował poza za barierkami ograniczającymi parkowanie.",
    8  : u"Pojazd był zaparkowany z dala od krawędzi jezdni.",
    7  : u"Pojazd niszczył chodnik",
    0  : u""
}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("file", help="json file")
    args = parser.parse_args()

    jsonfile = args.file
    texfile = jsonfile.replace('.json', '.tex')
    data = json.loads(open(jsonfile, encoding='utf-8').read())

    if data['pic1Url']:
        data['pic1Url'] = "\\includegraphics[width=0.5\\textwidth,height=0.5\\textwidth,clip=true,keepaspectratio=true]{" + (data['pic1Url']) + "}"

    if data['pic2Url']:
        data['pic2Url'] = "\\includegraphics[width=0.5\\textwidth,height=0.5\\textwidth,clip=true,keepaspectratio=true]{" + (data['pic2Url']) + "}"
    
    data['name_name'] = data['name']
    data['user_address'] = '!!! REPLACE !!!'
    data['user_personalId'] = '!!! REPLACE !!!'
    data['user_phone'] = '\\hspace{1cm}Tel: !!! REPLACE !!! \\\\'
    data['user_email'] = '\\hspace{1cm}Email: ' + data['email']
    data['app_number'] = '!!! REPLACE !!!'
    data['app_date'] = '!!! REPLACE !!!'
    data['app_hour'] = '!!! REPLACE !!!'
    data['app_brand'] = '!!! REPLACE !!!'
    data['app_plateId'] = '!!! REPLACE !!!'
    data['app_category'] = CATEGORIES[int(data['category'])]
    data['app_address'] = data['address']

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
