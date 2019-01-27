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
        self.cfg = Config()
        if(click_first):
            self.driver.find_element(*click_first).click()
            time.sleep(5)

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
        self.driver.find_element(*Locators.NEW_SUBMIT).click()
        time.sleep(2)
        assert "error" in self.driver.find_element(*Locators.NEW_ADDRESS).get_attribute('class')
        assert "error" in self.driver.find_element(*Locators.NEW_PLATEID).get_attribute('class')
        assert "error" in self.driver.find_element(*Locators.NEW_IMAGE1).get_attribute('class')
        assert "error" in self.driver.find_element(*Locators.NEW_IMAGE2).get_attribute('class')

    def is_other_comment_validation_working(self):
        self.driver.find_element(*Locators.NEW_CAT0).click()

        self.driver.find_element(*Locators.NEW_SUBMIT).click()
        assert "error" in self.driver.find_element(*Locators.NEW_COMMENT).get_attribute('class')
    
    def display_image_inputs(self):
        self.driver.execute_script("$('.image-upload input').css('display', 'block');")
        time.sleep(1)
    
    def test_context_image(self):
        self.display_image_inputs()
        self.driver.find_element(*Locators.NEW_IIMAGE1).send_keys(os.getcwd() + self.cfg.app['contextImage'])

        wait = WebDriverWait(self.driver, 20)
        wait.until(EC.text_to_be_present_in_element_value(Locators.NEW_ADDRESS, self.cfg.app['address']))
        
        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE1).get_attribute('class')
        assert not "error" in self.driver.find_element(*Locators.NEW_ADDRESS).get_attribute('class')

        assert self.cfg.app['address'] in self.driver.find_element(*Locators.NEW_ADDRESS).get_attribute("value")

    def test_car_image(self):
        self.display_image_inputs()
        self.driver.find_element(*Locators.NEW_IIMAGE2).send_keys(os.getcwd() + self.cfg.app['carImage'])
        
        wait = WebDriverWait(self.driver, 20)
        wait.until(EC.text_to_be_present_in_element_value(Locators.NEW_PLATEID, self.cfg.app['plateId']))
        
        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE2).get_attribute('class')
        assert not "error" in self.driver.find_element(*Locators.NEW_PLATEID).get_attribute('class')

        assert self.cfg.app['plateId'] in self.driver.find_element(*Locators.NEW_PLATEID).get_attribute("value")
    
    def test_invalid_image(self):
        self.display_image_inputs()
        self.driver.find_element(*Locators.NEW_IIMAGE1).send_keys(os.getcwd() + self.cfg.app['invalidImage'])
        self.driver.find_element(*Locators.NEW_IIMAGE2).send_keys(os.getcwd() + self.cfg.app['invalidImage'])
        
        time.sleep(5)

        assert "Twoje zdjęcie nie ma znaczników geolokacji" in self.driver.find_element(*Locators.NEW_ADD_HINT).text
        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE1).get_attribute('class')
        assert not "error" in self.driver.find_element(*Locators.NEW_IMAGE2).get_attribute('class')
    
    def test_invalid_image_submit(self):
        self.driver.find_element(*Locators.NEW_SUBMIT).click()

        assert "error" in self.driver.find_element(*Locators.NEW_ADDRESS).get_attribute('class')
        assert "error" in self.driver.find_element(*Locators.NEW_PLATEID).get_attribute('class')

    
    def review(self):
        self.driver.find_element(*Locators.NEW_COMMENT).clear()
        self.driver.find_element(*Locators.NEW_COMMENT).send_keys(self.cfg.app['comment'])
        self.driver.find_element(*Locators.NEW_SUBMIT).click()
        WebDriverWait(self.driver, 10).until(EC.title_contains('otwierd')) # [pP]otwierd[ź]

        text = self.driver.find_element(*Locators.CONFIRM_TEXT).text

        assert self.cfg.app['plateId'] in text
        assert self.cfg.app['address'] in text
        assert self.cfg.app['date'] in text
        assert self.cfg.app['time'] in text
        assert self.cfg.app['comment'] in text
        assert self.cfg.account['email'] in text
        
        self.driver.execute_script("window.history.go(-1)")
        WebDriverWait(self.driver, 10).until(EC.title_contains('owe '))
    
    def update(self):
        time.sleep(2)
        self.driver.find_element(*Locators.NEW_SUBMIT).click()
        WebDriverWait(self.driver, 10).until(EC.title_contains('otwierd')) # [pP]otwierd[ź]
    
    def commit(self):
        text = self.driver.find_element(*Locators.CONFIRM_TEXT).text

        assert self.cfg.app['plateId'] in text
        assert self.cfg.app['address'] in text
        assert self.cfg.app['date'] in text
        assert self.cfg.app['time'] in text
        assert self.cfg.app['comment'] in text
        assert self.cfg.account['email'] in text
    
    def fin(self):
        self.driver.execute_script("$('#form').submit()")
        time.sleep(1)
        text = self.driver.find_elements(*Locators.THANK_YOU)[1].text
        assert "UD/" in text
        assert "sm@um.szczecin.pl" in text
        
