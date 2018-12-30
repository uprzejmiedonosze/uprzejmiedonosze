from elements import *
from locators import *
from selenium.common.exceptions import NoSuchElementException
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait
import time

class BasePage(object):
    WAIT = 2

    def __init__(self, driver, click_first):
        self.driver = driver
        assert "https://" in self.driver.current_url
        assert "uprzejmiedonosze.net" in self.driver.current_url
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
        assert title in self.driver.title, "tytuł nie pasuje (jest {} zamiast {})".format(self.driver.title, title)
    
    def click_main(self):
        self.driver.find_element(*Locators.MAIN).click()
        time.sleep(2)
    
    def login(self):
        assert "zaloguj" in self.driver.title, "Próba logowania na stronie innej niz Zaloguj się (jest {})".format(self.driver.title)

        wait = WebDriverWait(self.driver, 10)
        wait.until(EC.visibility_of_element_located(Locators.LOGIN_BTN)).click()
        
        email = wait.until(EC.visibility_of_element_located(Locators.LOGIN_EMAIL))
        email.send_keys("szymon@nieradka.net")

        self.driver.find_element(*Locators.LOGIN_NEXT).click()

        password = wait.until(EC.element_to_be_clickable(Locators.LOGIN_PASWD))
        password.send_keys("")

        self.driver.find_element(*Locators.LOGIN_FIN).click()
        wait = WebDriverWait(self.driver, 10)
        wait.until(EC.title_contains('Start'))

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
        if not "Start" in self.driver.title:
            self.login()

class New(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.NEW)
        if not "Nowe" in self.driver.title:
            self.login()