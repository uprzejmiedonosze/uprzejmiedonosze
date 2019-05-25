from elements import *
from locators import *
from selenium.common.exceptions import NoSuchElementException
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait
import time
import os
import sys
import configparser
import logging

logger = logging.getLogger(__name__)
logger.level = logging.DEBUG
logger.addHandler(logging.StreamHandler(sys.stdout))

class Config(object):
    config = configparser.ConfigParser()
    def __init__(self, file='./config.ini'):
        self.config.read(file)
        self.account = self.config['account']
        self.app = self.config['app']

class BasePage(object):
    WAIT = 2
    cfg = None

    def __init__(self, driver, click_first):
        self.driver = driver
        assert "https://" in self.driver.current_url
        assert "uprzejmiedonosze.net" in self.driver.current_url
        self.driver.find_element(*Locators.MAIN_MENU).click()
        time.sleep(1)
        self.driver.execute_script("$('.ui-panel-dismiss').hide();")
        self.cfg = Config()
        if(click_first):
            self.driver.find_element(*click_first).click()
            time.sleep(3)

    def is_new_matches(self, text="Zgłoś"):
        try:
            new = self.driver.find_elements(*Locators.START)
        except NoSuchElementException:
            assert False, "nie mogę znaleźć przycisku nowego zgłoszenia"
        assert len(new) > 0, "nie mogę znaleźć przycisku nowego zgłoszenia"
    
    def is_title_matches(self, title):
        assert title in self.driver.title, "tytuł nie pasuje (jest '{}' zamiast '{}')".format(self.driver.title, title)
    
    def click_main(self):
        self.driver.find_element(*Locators.MAIN).click()
        time.sleep(2)
    
    def login(self):
        assert "zaloguj" in self.driver.title.lower(), "Próba logowania na stronie innej niz Zaloguj się (jest {})".format(self.driver.title)

        wait = WebDriverWait(self.driver, 10)
        wait.until(EC.visibility_of_element_located(Locators.LOGIN_BTN)).click()
        
        email = wait.until(EC.visibility_of_element_located(Locators.LOGIN_EMAIL))
        email.send_keys(self.cfg.account['email'])

        self.driver.find_element(*Locators.LOGIN_NEXT).click()

        password = wait.until(EC.element_to_be_clickable(Locators.LOGIN_PASWD))
        password.send_keys(self.cfg.account['pass'])

        self.driver.find_element(*Locators.LOGIN_FIN).click()
        wait = WebDriverWait(self.driver, 10)
        wait.until(EC.title_contains('tart')) # [sS]tart

    def get_content(self):
        time.sleep(3)
        for tries in range(0, 3):
            content = self.driver.find_elements(*Locators.CONTENT)
            text = content[len(content) - 1].text
            if len(text) > 0:
                break
            time.sleep(tries + 1)
        return text
    
    def back(self, wait_for_title = None):
        self.driver.execute_script("window.history.go(-1)")
        if wait_for_title:
            WebDriverWait(self.driver, 10).until(EC.title_contains(wait_for_title))

class MainPage(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, None)
        pass

class Changelog(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.CHANGELOG)

class Project(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.PROJECT)

class RTD(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.RTD)

class Start(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.START)
        if not "start" in self.driver.title.lower():
            self.login()

class New(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.START)
        self.driver.find_element(*Locators.NEW).click()
        time.sleep(2)
        if not "nowe " in self.driver.title.lower():
            self.login()
    
    def is_validation_empty_working(self):
        self.confirm(False)
        time.sleep(2)
        assert "error" in self.driver.find_element(*Locators.NEW_PLATEID).get_attribute('class'), \
            "Przy próbie wysłania pustego zgłoszenia tablica rej. powininna mieć klasę error"
        assert "error" in self.driver.find_element(*Locators.NEW_IMAGE1).get_attribute('class'), \
            "Przy próbie wysłania pustego zgłoszenia zdjęcia powininny mieć klasę error"
        assert "error" in self.driver.find_element(*Locators.NEW_IMAGE2).get_attribute('class'), \
            "Przy próbie wysłania pustego zgłoszenia zdjęcia powininny mieć klasę error"

    def is_other_comment_validation_working(self):
        self.driver.find_element(*Locators.NEW_CAT0).click()

        self.confirm(False)
        assert "error" in self.driver.find_element(*Locators.NEW_COMMENT).get_attribute('class'), \
            "Przy kategorii 'inne' pole komentarz jest wymagane"
    
    def display_image_inputs(self):
        self.driver.execute_script("$('.image-upload input').css('display', 'block');")
        time.sleep(1)

    def reload_on_edit(self):
        try:
            self.driver.find_element(*Locators.NEW_CLEANUP).click()
            time.sleep(1)
        except NoSuchElementException:
            pass
    
    def test_context_image(self):
        self.display_image_inputs()
        self.driver.find_element(*Locators.NEW_IIMAGE1).send_keys(os.getcwd() + self.cfg.app['contextImage'])

        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE1).get_attribute('class')

    def test_car_image(self):
        self.display_image_inputs()
        self.driver.find_element(*Locators.NEW_IIMAGE2).send_keys(os.getcwd() + self.cfg.app['carImage'])

        wait = WebDriverWait(self.driver, 20)
        wait.until(EC.text_to_be_present_in_element_value(Locators.NEW_ADDRESS, self.cfg.app['address']))

        wait = WebDriverWait(self.driver, 20)
        wait.until(EC.text_to_be_present_in_element_value(Locators.NEW_PLATEID, self.cfg.app['plateId']))

        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE2).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert not "error" in self.driver.find_element(*Locators.NEW_PLATEID).get_attribute('class'), \
            "Po załadowaniu zdjęcia z tablicą nadal jest widoczny błąd walidacji przy numerze rejestracyjnym"
        assert not "error" in self.driver.find_element(*Locators.NEW_ADDRESS).get_attribute('class'), \
            "Po załadowaniu zdjęcia z GEO nadal jest widoczny błąd walidacji przy adresie"

        time.sleep(1)
        assert self.driver.find_element(*Locators.NEW_PLATEIMG).is_displayed()

        address = self.driver.find_element(*Locators.NEW_ADDRESS).get_attribute("value")
        assert self.cfg.app['address'] in address, \
            "Adres pobrany ze zdjęcia jest nieprawidłowy ({} zamiast {})".format(self.cfg.app['address'], address) 

        plate = self.driver.find_element(*Locators.NEW_PLATEID).get_attribute("value")
        assert self.cfg.app['plateId'] in plate, \
            "Tablica rej pobrana ze zdjęcia jest nieprawidłowa ({} zamiast {})".format(self.cfg.app['plateId'], plate) 
    
    def test_invalid_image(self):
        self.display_image_inputs()
        self.driver.find_element(*Locators.NEW_IIMAGE1).send_keys(os.getcwd() + self.cfg.app['invalidImage'])
        self.driver.find_element(*Locators.NEW_IIMAGE2).send_keys(os.getcwd() + self.cfg.app['invalidImage'])
        
        time.sleep(3)

        assert "Twoje zdjęcie nie ma znaczników geolokacji" in self.driver.find_element(*Locators.NEW_ADD_HINT).text, \
            "Brakuje tekstu: Twoje zdjęcie nie ma znaczników geolokacji"
        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE1).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE2).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE2).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert "około" in self.driver.find_element(*Locators.NEW_PRECISE).text, \
            "Załadowane zdjęcie bez EXIFa, a czas nie pokazuje w formacie XXXX około HH:mm"
        assert "ui-state-disabled" in self.driver.find_element(*Locators.NEW_DP).get_attribute('class'), \
            "Przycisk + przy dacie nie jest domyślnie wyłączony"
        assert "ui-state-disabled" in self.driver.find_element(*Locators.NEW_HP).get_attribute('class'), \
            "Przycisk + przy godzinie nie jest domyślnie wyłączony"
        
        assert not self.driver.find_element(*Locators.NEW_PLATEIMG).is_displayed(), \
            "Po załadowaniu zdjęcia bez tablic nadal jest widoczna miniaturka tablicy rejestracyjnej"
    
    def test_invalid_image_submit(self):
        self.confirm(False)

        assert "error" in self.driver.find_element(*Locators.NEW_PLATEID).get_attribute('class'), \
            "Pusty numer rej (po załadowaniu zdjęcia bez tablic), a nie ma błędu"
    
    def confirm(self, shouldPass = True):
        self.driver.find_element(*Locators.NEW_SUBMIT).click()
        if shouldPass:
            WebDriverWait(self.driver, 10).until(EC.title_contains('otwierd')) # [pP]otwierd[ź]

    def review(self):
        self.driver.find_element(*Locators.NEW_COMMENT).clear()
        self.driver.find_element(*Locators.NEW_COMMENT).send_keys(self.cfg.app['comment'])
        self.confirm(True)

        text = self.driver.find_element(*Locators.CONFIRM_TEXT).text

        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text, "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])
        assert self.cfg.app['comment'] in text, "Brakuje {} w tekście review".format(self.cfg.app['comment'])
        assert self.cfg.account['email'] in text, "Brakuje {} w tekście review".format(self.cfg.app['email'])
        
        self.back('owe ')
    
    def update(self):
        time.sleep(2)
        self.confirm()
    
    def commit(self, has_comment = True):
        text = self.driver.find_element(*Locators.CONFIRM_TEXT).text

        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text, "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])
        if(has_comment):
            assert self.cfg.app['comment'] in text
        assert self.cfg.account['email'] in text
    
    def fin(self):
        self.driver.execute_script("$('#form').submit()")
        text = self.get_content()
        assert "UD/" in text

    def app_page(self):
        self.driver.find_element(*Locators.MYAPPS_FIRSTL).click() # first link with /ud-
        text = self.get_content()
        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text, "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])
        #assert self.cfg.app['comment'] in text
        assert self.cfg.account['email'] in text, "Brakuje {} w tekście review".format(self.cfg.app['email'])

        return self.driver.find_element(*Locators.APP_PDF_LINK).get_attribute('href')
    
    def check_pdf(self, url):
        import urllib.request
        urllib.request.urlretrieve(url, '/tmp/f.pdf')
        import PyPDF2
        pdf_file = open('/tmp/f.pdf', 'rb')
        read_pdf = PyPDF2.PdfFileReader(pdf_file)
        assert read_pdf.getNumPages() == 1
        page = read_pdf.getPage(0)
        text = page.extractText()

        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text or self.cfg.app['address'].replace(' ', '') in text, \
            "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])
        assert self.cfg.account['email'] in text, "Brakuje {} w tekście review".format(self.cfg.app['email'])
    
    def check_default_statements(self):
        # statemens should on ON by default
        assert self.driver.find_element(*Locators.NEW_WITNESS).get_attribute('value')

    def flip_witness_statement(self):
        self.driver.execute_script("document.getElementById('witness').scrollIntoView(true);")
        self.driver.find_element(*Locators.NEW_WITNESSD).click()

    def test_expose_statement(self, value):
        page_content = self.get_content()
        if value:
            assert 'Równocześnie proszę o niezamieszczanie w protokole' in page_content, \
                "Brakuje 'Równocześnie proszę o niezamieszczanie w protokole' a powinno być"
        else:
            assert not 'Równocześnie proszę o niezamieszczanie w protokole' in page_content, \
                "Jest 'Równocześnie proszę o niezamieszczanie w protokole' a nie powinno być"
    
    def test_witness_statement(self, value):
        page_content = self.get_content()
        if value:
            assert 'Nie byłem świadkiem samego momentu parkowania' in page_content, \
                "Brakuje 'Nie byłem świadkiem samego momentu parkowania', a powinno być"
        else:
            assert not 'Nie byłem świadkiem samego momentu parkowania' in page_content, \
                "Jest 'Nie byłem świadkiem samego momentu parkowania', a nie powinno być"

class MyApps(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.MYAPPS)
    
    def check_list(self):
        self.driver.find_element(*Locators.MYAPPS_EXPAND).click() # expand first item
        text = self.driver.find_element(*Locators.MYAPPS_FIRST).text
        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text, "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])

    def check_first(self, has_comment = True):
        first_element = self.driver.find_element(*Locators.MYAPPS_EXPAND)
        first_element.find_element(By.CSS_SELECTOR, "h3 a").click() # expand first item
        time.sleep(1) # lazyload images
        first_element.find_element(By.CSS_SELECTOR, ".images img").click() # and click first photo

        text = self.get_content()
        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text, "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])
        if(has_comment):
            assert self.cfg.app['comment'] in text, \
                "Brakuje {} w tekście review".format(self.cfg.app['comment'])
        assert self.cfg.account['email'] in text, "Brakuje {} w tekście review".format(self.cfg.app['email'])
        assert 'Zgłoszenie wykroczenia UD/' in text, \
            "Brakuje intro 'Zgłoszenie wykroczenia UD/' w opisie"
        assert 'Jestem świadomy odpowiedzialności karnej' in text, \
            "Brakuje 'Jestem świadomy odpowiedzialności karnej' w opisie"
        #self.driver.find_element(*Locators.BTN_LEFT).click()

class Wysylka(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.MYAPPS)

        self.driver.find_element(*Locators.NEW).click()
        time.sleep(2)
        if not "nowe " in self.driver.title.lower():
            self.login()