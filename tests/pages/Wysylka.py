from BasePage import BasePage
from selenium.webdriver.common.by import By

class Wysylka(BasePage):
    NEW         = (By.XPATH, "//a[@href='nowe-zgloszenie.html']")
    MYAPPS      = (By.XPATH, "//a[@href='/moje-zgloszenia.html']")

    def __init__(self, driver):
        BasePage.__init__(self, driver, self.MYAPPS)

        self.driver.find_element(*self.NEW).click()
        time.sleep(2)
        if not "nowe " in self.driver.title.lower():
            self.login()