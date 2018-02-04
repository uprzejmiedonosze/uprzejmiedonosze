#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import argparse
import json
import os
import subprocess
from string import Template

from dateutil import parser

CDN_DIR = 'tex/cdn/'

CATEGORIES = {
    4  : u"Pojazd zastawiał chodnik (mniej niż 1.5m).",
    2  : u"Pojazd znajdował się mniej niż 15 m od przystanku.",
    3  : u"Pojazd znajdował się mniej niż 10m od skrzyżowania.",
    9  : u"Pojazd blokował ścieżkę rowerową.",
    5  : u"Pojazd znajdował się mniej niż 10m od przejścia dla pieszych.",
    6  : u"Pojazd był zaparkowany na trawniku/w parku.",
    10 : u"Pojazd znajdował poza za barierkami ograniczającymi parkowanie.",
    8  : u"Pojazd był zaparkowany z dala od krawędzi jezdni.",
    7  : u"Pojazd niszczył chodnik.",
    1  : u"Pojazd był zaparkowany na chodniku w miejscu niedozwolonym.",
    0  : u""
}

def main():
    argparser = argparse.ArgumentParser()
    argparser.add_argument("applicationId", help="ID of the application")
    args = argparser.parse_args()

    application_id = args.applicationId
    texfile = application_id + '.tex'
    data = get_application(application_id)

    if data['contextImage']:
        data['contextImage'] = "\\includegraphics[width=0.5\\textwidth,height=0.5\\textwidth,clip=true,keepaspectratio=true]{" + (data['contextImage']['url']) + "}"

    if data['carImage']:
        data['carImage'] = "\\includegraphics[width=0.5\\textwidth,height=0.5\\textwidth,clip=true,keepaspectratio=true]{" + (data['carImage']['url']) + "}"

    if data['carInfo']:
        if 'plateImage' in data['carInfo']:
            data['plateImage'] = "\\includegraphics[width=0.2\\textwidth,height=0.2\\textwidth,clip=true,keepaspectratio=true]{" + (data['carInfo']['plateImage']) + "}"
        else:
            data['plateImage'] = ''

    data['name_name'] = data['user']['name']
    data['user_address'] = data['user']['address']
    data['user_phone'] = '\\hspace{1cm}Tel: ' + (data['user']['msisdn']) + '\\\\'
    data['user_email'] = '\\hspace{1cm}Email: ' + (data['user']['email'])
    data['app_number'] = data['number']
    date = parser.parse(data['date'])
    data['app_date'] = date.strftime('%d.%m.%Y')
    data['app_hour'] = date.strftime('%H:%M')
    data['city'] = data['address']['city']

    data['app_plateId'] = data['carInfo']['plateId']
    data['app_category'] = CATEGORIES[int(data['category'])]
    data['app_address'] = data['address']['address']

    filein = open('template.tpl', encoding='utf-8')
    src = Template(filein.read())
    tex = src.substitute(data)

    with open(texfile, 'w') as f:
        f.write(tex)

    cmd = ['/Library/TeX/texbin/pdflatex', '-interaction', 'nonstopmode', texfile]
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, cwd='.')
    proc.communicate(timeout=5)

    retcode = proc.returncode
    unlink_files(application_id)
    os.rename(application_id + '.pdf', CDN_DIR + application_id + '.pdf')
    if retcode != 0:
        raise ValueError('Error {} executing command: {}'.format(retcode, ' '.join(cmd)))

def get_application(application_id):
    import sqlite3
    connection = sqlite3.connect('db/store.sqlite')
    cursor = connection.cursor()
    cursor.execute("select * from applications where key = '{0}';".format(application_id))
    return json.loads(cursor.fetchone()[1])

def unlink_files(application_id):
    os.unlink(application_id + '.tex')
    os.unlink(application_id + '.out')
    os.unlink(application_id + '.aux')
    os.unlink(application_id + '.log')

if __name__ == '__main__':
    main()
