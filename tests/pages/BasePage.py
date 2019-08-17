from pages.Config import Config
from selenium.common.exceptions import NoSuchElementException
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.common.by import By
import time
import os
import sys
import logging

logger = logging.getLogger(__name__)
logger.level = logging.DEBUG
logger.addHandler(logging.StreamHandler(sys.stdout))

class BasePage(object):
    START       = (By.XPATH, "//a[@href='/start.html']")
    MAIN        = (By.XPATH, "//a[@href='/']")

    NEW         = (By.XPATH, "//a[@href='nowe-zgloszenie.html']")
    MYAPPS      = (By.XPATH, "//a[@href='/moje-zgloszenia.html']")
    MAIN_MENU   = (By.ID,    "menuToggle")

    CONTENT     = (By.CSS_SELECTOR, "div.ui-content")

    LOGIN_BTN   = (By.XPATH, "//button[contains(@class, 'firebaseui-idp-button')]")
    LOGIN_EMAIL = (By.XPATH, "//input[@id='identifierId']")
    LOGIN_NEXT  = (By.ID,    "identifierNext")
    LOGIN_PASWD = (By.XPATH, "//input[@name='password']")
    LOGIN_FIN   = (By.ID,    "passwordNext")

    WAIT = 2
    cfg = None

    def __init__(self, driver, click_first):
        self.driver = driver
        assert "https://" in self.driver.current_url
        assert "uprzejmiedonosze.net" in self.driver.current_url
        self.driver.find_element(*self.MAIN_MENU).click()
        time.sleep(1)
        self.driver.execute_script("$('.ui-panel-dismiss').hide();")
        self.cfg = Config()
        if(click_first):
            self.driver.find_element(*click_first).click()
            time.sleep(3)

    def is_new_matches(self, text="Zgłoś"):
        try:
            new = self.driver.find_elements(*self.START)
        except NoSuchElementException:
            assert False, "nie mogę znaleźć przycisku nowego zgłoszenia"
        assert len(new) > 0, "nie mogę znaleźć przycisku nowego zgłoszenia"
    
    def is_title_matches(self, title):
        assert title.lower() in self.driver.title.lower(), "tytuł nie pasuje (jest '{}' zamiast '{}')".format(self.driver.title, title)
    
    def click_main(self):
        self.driver.find_element(*self.MAIN).click()
        time.sleep(2)
    
    def login(self):
        assert "zaloguj" in self.driver.title.lower(), "Próba logowania na stronie innej niz Zaloguj się (jest {})".format(self.driver.title)

        wait = WebDriverWait(self.driver, 10)
        wait.until(EC.visibility_of_element_located(self.LOGIN_BTN)).click()
        
        email = wait.until(EC.visibility_of_element_located(self.LOGIN_EMAIL))
        email.send_keys(self.cfg.account['email'])

        self.driver.find_element(*self.LOGIN_NEXT).click()

        password = wait.until(EC.element_to_be_clickable(self.LOGIN_PASWD))
        password.send_keys(self.cfg.account['pass'])

        self.driver.find_element(*self.LOGIN_FIN).click()
        wait = WebDriverWait(self.driver, 10)
        wait.until(EC.title_contains('tart')) # [sS]tart

    def get_content(self):
        time.sleep(3)
        for tries in range(0, 3):
            content = self.driver.find_elements(*self.CONTENT)
            text = content[len(content) - 1].text
            if len(text) > 0:
                break
            time.sleep(tries + 1)
        return text
    
    def back(self, wait_for_title = None):
        self.driver.execute_script("window.history.go(-1)")
        if wait_for_title:
            WebDriverWait(self.driver, 10).until(EC.title_contains(wait_for_title))

class Start(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, self.START)
        if not "start" in self.driver.title.lower():
            self.login()