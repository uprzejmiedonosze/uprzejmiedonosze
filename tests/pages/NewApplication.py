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
    

    def is_other_comment_validation_working(self):
        self.driver.find_element(*self.NEW_CAT0).click()

        self.confirm(False)
        assert "error" in self.driver.find_element(*self.NEW_COMMENT).get_attribute('class'), \
            "Przy kategorii 'inne' pole komentarz jest wymagane"
    
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

    def test_expose_statement(self, value):
        page_content = self.get_content()
        if value:
            assert 'Równocześnie proszę o niezamieszczanie w protokole' in page_content, \
                "Brakuje 'Równocześnie proszę o niezamieszczanie w protokole' a powinno być"
        else:
            assert not 'Równocześnie proszę o niezamieszczanie w protokole' in page_content, \
                "Jest 'Równocześnie proszę o niezamieszczanie w protokole' a nie powinno być"
