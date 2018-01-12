#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import argparse
import json
import os
import subprocess
from string import Template

APP_DIR = 'cdn/'

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
    0  : u""
}


def main():
    parser = argparse.ArgumentParser()
    #parser.add_argument("applicationId", help="ID of the application")
    #args = parser.parse_args()

    applicationId = 'c1433ba5-ab61-4924-a88c-a210962f2505' #args.applicationId
    texfile = applicationId + '.tex'
    data = get_application(applicationId)

    if data['contextImage']:
        data['contextImage'] = "\\includegraphics[width=0.5\\textwidth,height=0.5\\textwidth,clip=true,keepaspectratio=true]{" + (data['contextImage']['url']) + "}"

    if data['carImage']:
        data['carImage'] = "\\includegraphics[width=0.5\\textwidth,height=0.5\\textwidth,clip=true,keepaspectratio=true]{" + (data['carImage']['url']) + "}"

    data['name_name'] = data['user']['name']
    data['user_address'] = '!!! REPLACE !!!'
    data['user_personalId'] = '!!! REPLACE !!!'
    data['user_phone'] = '\\hspace{1cm}Tel: ' + (data['user']['msisdn']) + '\\\\'
    data['user_email'] = '\\hspace{1cm}Email: ' + (data['user']['email'])
    data['app_number'] = 'UD/1/2' #data['number'] TODO
    data['app_date'] = data['date']
    data['app_hour'] = data['date']

    data['app_plateId'] = data['carInfo']['plateId']
    data['app_category'] = CATEGORIES[int(data['category'])]
    data['app_address'] = data['address']['street'] # TODO

    filein = open('tex/template.tpl', encoding='utf-8')
    src = Template(filein.read())
    tex = src.substitute(data)

    with open(texfile, 'w') as f:
        f.write(tex)

    cmd = ['pdflatex', '-interaction', 'nonstopmode', texfile]
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, cwd='.')
    proc.communicate(timeout=5)

    retcode = proc.returncode
    if retcode != 0:
        raise ValueError('Error {} executing command: {}'.format(retcode, ' '.join(cmd)))

    os.unlink(texfile)
    os.unlink(applicationId + '.out')
    os.unlink(applicationId + '.aux')
    os.unlink(applicationId + '.log')

def get_application(applicationId):
    import sqlite3
    connection = sqlite3.connect('db/store.sqlite')
    cursor = connection.cursor()
    cursor.execute("select * from applications where key = '{0}';".format(applicationId))
    return json.loads(cursor.fetchone()[1])

if __name__ == '__main__':
    main()
