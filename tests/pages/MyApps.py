import time
from pages.BasePage import BasePage
from selenium.webdriver.common.by import By

class MyApps(BasePage):
    MYAPPS      = (By.XPATH, "//a[@href='/moje-zgloszenia.html']")
    MYAPPS_FIRST = (By.CSS_SELECTOR, "div.application")
    MYAPPS_EXPAND= (By.CSS_SELECTOR, "div.application")
    BTN_LEFT    = (By.CSS_SELECTOR, "a.ui-btn-left")

    def __init__(self, driver):
        BasePage.__init__(self, driver, self.MYAPPS)
    
    def check_list(self):
        self.driver.find_element(*self.MYAPPS_EXPAND).click() # expand first item
        text = self.driver.find_element(*self.MYAPPS_FIRST).text
        assert self.cfg.app['plateId'] in text, "Brakuje {} w tekście review".format(self.cfg.app['plateId'])
        assert self.cfg.app['address'] in text, "Brakuje {} w tekście review".format(self.cfg.app['address'])
        assert self.cfg.app['date'] in text, "Brakuje {} w tekście review".format(self.cfg.app['date'])
        assert self.cfg.app['time'] in text, "Brakuje {} w tekście review".format(self.cfg.app['time'])

    def check_first(self, has_comment = True):
        first_element = self.driver.find_element(*self.MYAPPS_EXPAND)
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
        #self.driver.find_element(*self.BTN_LEFT).click()
