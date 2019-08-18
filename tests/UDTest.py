import os
import time
import unittest
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.firefox.options import Options


class UDTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        profile = webdriver.FirefoxProfile('/Users/szn/Sites/uprzejmiedonosze.net/webapp/tests/selenium.ff-profile')
        options = Options()
        #options.add_argument("--headless")
        cls.driver = webdriver.Firefox(firefox_profile=profile, firefox_options=options,
            firefox_binary='/Applications/Firefox.app/Contents/MacOS/firefox-bin')

        cls.driver.implicitly_wait(1)
        cls.driver.set_window_size(800, 900)

    @classmethod
    def tearDownClass(cls):
        cls.driver.quit()

    def setUp(self):
        self.driver.get('http://staging.uprzejmiedonosze.net')
        time.sleep(1)
