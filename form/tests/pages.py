from elements import *
from locators import *
from selenium.common.exceptions import NoSuchElementException
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
            new = self.driver.find_elements(*Locators.NEW)
        except NoSuchElementException:
            assert False, "nie mogę znaleźć przycisku nowego zgłoszenia"
        assert len(new) > 0, "nie mogę znaleźć przycisku nowego zgłoszenia"
    
    def is_title_matches(self, title):
        assert title in self.driver.title, "tytuł nie pasuje (jest {} zamiast {})".format(self.driver.title, title)
    
    def click_main(self):
        self.driver.find_element(*Locators.MAIN).click()
        time.sleep(2)

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

class New(BasePage):
    def __init__(self, driver):
        BasePage.__init__(self, driver, Locators.NEW)
