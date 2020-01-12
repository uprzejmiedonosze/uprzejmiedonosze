import os
import time
from pages.BasePage import BasePage
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.common.exceptions import NoSuchElementException
from selenium.webdriver.support import expected_conditions as EC

class NewApplication(BasePage):
    START       = (By.XPATH, "//a[@href='/start.html']")

    NEW         = (By.XPATH, "//a[@href='nowe-zgloszenie.html']")
    MYAPPS      = (By.XPATH, "//a[@href='/moje-zgloszenia.html']")

    CONTENT     = (By.CSS_SELECTOR, "div.ui-content")

    NEW_SUBMIT  = (By.ID,    "form-submit")
    NEW_ADDRESS = (By.ID,    "lokalizacja")
    NEW_PLATEID = (By.ID,    "plateId")
    NEW_COMMENT = (By.ID,    "comment")
    NEW_IMAGE1  = (By.CSS_SELECTOR, "div.ui-block-a.image-upload")
    NEW_IMAGE2  = (By.CSS_SELECTOR, "div.ui-block-b.image-upload")
    NEW_IIMAGE1 = (By.ID,    "contextImage")
    NEW_IIMAGE2 = (By.ID,    "carImage")
    NEW_CAT0    = (By.XPATH, "//label[@for='0']")
    NEW_ADD_HINT= (By.ID,    "addressHint")
    NEW_PLATEIMG= (By.ID,    "plateImage")
    NEW_CLEANUP = (By.CSS_SELECTOR, "a.cleanup")
    NEW_EXPOSE  = (By.ID,    "exposeData")
    NEW_EXPOSED = (By.XPATH, '//input[@id="exposeData"]/..')
    NEW_WITNESS = (By.ID,    "witness")
    NEW_WITNESSD= (By.XPATH, '//input[@id="witness"]/..')
    NEW_PRECISE = (By.ID,    "dtPrecise")
    NEW_DP      = (By.ID,    "dp")
    NEW_HP      = (By.ID,    "hp")

    CONFIRM_TEXT= (By.CSS_SELECTOR, "div.ui-content > div.ui-body")

    MYAPPS_FIRST = (By.CSS_SELECTOR, "div.application")
    MYAPPS_FIRSTL= (By.XPATH, "//a[contains(@href, 'ud-')]")

    APP_PDF_LINK= (By.XPATH, "//a[contains(@href, '.pdf')]")

    def __init__(self, driver):
        BasePage.__init__(self, driver, self.START)
        self.driver.find_element(*self.NEW).click()
        time.sleep(2)
        if not "nowe " in self.driver.title.lower():
            self.login()
    
    def is_validation_empty_working(self):
        self.confirm(False)
        time.sleep(2)
        assert "error" in self.driver.find_element(*self.NEW_PLATEID).get_attribute('class'), \
            "Przy próbie wysłania pustego zgłoszenia tablica rej. powininna mieć klasę error"
        assert "error" in self.driver.find_element(*self.NEW_IMAGE1).get_attribute('class'), \
            "Przy próbie wysłania pustego zgłoszenia zdjęcia powininny mieć klasę error"
        assert "error" in self.driver.find_element(*self.NEW_IMAGE2).get_attribute('class'), \
            "Przy próbie wysłania pustego zgłoszenia zdjęcia powininny mieć klasę error"

    def is_other_comment_validation_working(self):
        self.driver.find_element(*self.NEW_CAT0).click()

        self.confirm(False)
        assert "error" in self.driver.find_element(*self.NEW_COMMENT).get_attribute('class'), \
            "Przy kategorii 'inne' pole komentarz jest wymagane"
    
    def display_image_inputs(self):
        self.driver.execute_script("$('.image-upload input').css('display', 'block');")
        time.sleep(1)

    def reload_on_edit(self):
        try:
            self.driver.find_element(*self.NEW_CLEANUP).click()
            time.sleep(1)
        except NoSuchElementException:
            pass
    
    def test_context_image(self):
        self.display_image_inputs()
        self.driver.find_element(*self.NEW_IIMAGE1).send_keys(os.getcwd() + self.cfg.app['contextImage'])

        assert not "error" in self.driver.find_element(*self.NEW_IMAGE1).get_attribute('class')

    def test_car_image(self):
        self.display_image_inputs()
        self.driver.find_element(*self.NEW_IIMAGE2).send_keys(os.getcwd() + self.cfg.app['carImage'])

        wait = WebDriverWait(self.driver, 20)
        wait.until(EC.text_to_be_present_in_element_value(self.NEW_ADDRESS, self.cfg.app['address']))

        wait = WebDriverWait(self.driver, 20)
        wait.until(EC.text_to_be_present_in_element_value(self.NEW_PLATEID, self.cfg.app['plateId']))

        assert not "error" in self.driver.find_element(*self.NEW_IMAGE2).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert not "error" in self.driver.find_element(*self.NEW_PLATEID).get_attribute('class'), \
            "Po załadowaniu zdjęcia z tablicą nadal jest widoczny błąd walidacji przy numerze rejestracyjnym"
        assert not "error" in self.driver.find_element(*self.NEW_ADDRESS).get_attribute('class'), \
            "Po załadowaniu zdjęcia z GEO nadal jest widoczny błąd walidacji przy adresie"

        time.sleep(1)
        assert self.driver.find_element(*self.NEW_PLATEIMG).is_displayed()

        address = self.driver.find_element(*self.NEW_ADDRESS).get_attribute("value")
        assert self.cfg.app['address'] in address, \
            "Adres pobrany ze zdjęcia jest nieprawidłowy ({} zamiast {})".format(self.cfg.app['address'], address) 

        plate = self.driver.find_element(*self.NEW_PLATEID).get_attribute("value")
        assert self.cfg.app['plateId'] in plate, \
            "Tablica rej pobrana ze zdjęcia jest nieprawidłowa ({} zamiast {})".format(self.cfg.app['plateId'], plate) 
    
    def test_invalid_image(self):
        self.display_image_inputs()
        self.driver.find_element(*self.NEW_IIMAGE1).send_keys(os.getcwd() + self.cfg.app['invalidImage'])
        self.driver.find_element(*self.NEW_IIMAGE2).send_keys(os.getcwd() + self.cfg.app['invalidImage'])
        
        time.sleep(3)

        assert "Twoje zdjęcie nie ma znaczników geolokacji" in self.driver.find_element(*self.NEW_ADD_HINT).text, \
            "Brakuje tekstu: Twoje zdjęcie nie ma znaczników geolokacji"
        assert not "error" in self.driver.find_element(*self.NEW_IMAGE1).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert not "error" in self.driver.find_element(*self.NEW_IMAGE2).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert not "error" in self.driver.find_element(*self.NEW_IMAGE2).get_attribute('class'), \
            "Po załadowaniu zdjęcia nadal jest widoczny błąd walidacji"
        assert "około" in self.driver.find_element(*self.NEW_PRECISE).text, \
            "Załadowane zdjęcie bez EXIFa, a czas nie pokazuje w formacie XXXX około HH:mm"
        assert "ui-state-disabled" in self.driver.find_element(*self.NEW_DP).get_attribute('class'), \
            "Przycisk + przy dacie nie jest domyślnie wyłączony"
        assert "ui-state-disabled" in self.driver.find_element(*self.NEW_HP).get_attribute('class'), \
            "Przycisk + przy godzinie nie jest domyślnie wyłączony"
        
        assert not self.driver.find_element(*self.NEW_PLATEIMG).is_displayed(), \
            "Po załadowaniu zdjęcia bez tablic nadal jest widoczna miniaturka tablicy rejestracyjnej"
    
    def test_invalid_image_submit(self):
        self.confirm(False)

        assert "error" in self.driver.find_element(*self.NEW_PLATEID).get_attribute('class'), \
            "Pusty numer rej (po załadowaniu zdjęcia bez tablic), a nie ma błędu"
    
    def confirm(self, shouldPass = True):
        self.driver.find_element(*self.NEW_SUBMIT).click()
        if shouldPass:
            WebDriverWait(self.driver, 20).until(EC.title_contains('otwierd')) # [pP]otwierd[ź]

    def review(self):
        self.driver.find_element(*self.NEW_COMMENT).clear()
        self.driver.find_element(*self.NEW_COMMENT).send_keys(self.cfg.app['comment'])
        self.confirm(True)

        text = self.driver.find_element(*self.CONFIRM_TEXT).text

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
        text = self.driver.find_element(*self.CONFIRM_TEXT).text

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
        self.driver.find_element(*self.MYAPPS_FIRSTL).click() # first link with /ud-
        text = self.get_content()
        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text, "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])
        #assert self.cfg.app['comment'] in text
        assert self.cfg.account['email'] in text, "Brakuje {} w tekście review".format(self.cfg.app['email'])

        return self.driver.find_element(*self.APP_PDF_LINK).get_attribute('href')
    
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
        assert self.driver.find_element(*self.NEW_WITNESS).get_attribute('value')

    def flip_witness_statement(self):
        self.driver.execute_script("document.getElementById('witness').scrollIntoView(true);")
        self.driver.find_element(*self.NEW_WITNESSD).click()

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
