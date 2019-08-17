import unittest
from selenium import webdriver
from selenium.webdriver.firefox.options import Options
from selenium.webdriver.common.by import By
from StaticPage import StaticPage
import time
import os

class UDStatic_test(unittest.TestCase):
    MAIN        = (By.XPATH, "//a[@href='/']")
    CHANGELOG   = (By.XPATH, "//a[@href='/changelog.html']")
    PROJECT     = (By.XPATH, "//a[@href='/projekt.html']")
    PRZEPISY    = (By.XPATH, "//a[@href='/przepisy.html']")
    RTD         = (By.XPATH, "//a[@href='robtodobrze.html']")
    FAQ         = (By.XPATH, "//a[@href='/faq.html']")
    START       = (By.XPATH, "//a[@href='/start.html']")
    STATYSTYKI  = (By.XPATH, "//a[@href='/statystyki.html']")
    MANDAT      = (By.XPATH, "//a[@href='/mandat.html']")
    
    @classmethod
    def setUpClass(cls):
        profile = webdriver.FirefoxProfile('/Users/szn/Sites/uprzejmiedonosze.net/webapp/tests/selenium.ff-profile')
        options = Options()
        options.add_argument("--headless")
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

    def test_ssl(self):
        assert "https://" in self.driver.current_url

    def test_main_page(self):
        main_page = StaticPage(self.driver, None)
        main_page.is_title_matches("Uprzejmie")
        main_page.is_new_matches()
    
    def test_changelog(self):
        changelog = StaticPage(self.driver, self.CHANGELOG)
        changelog.is_title_matches("istoria"),
        changelog.is_new_matches()
    
    def test_project(self):
        project = StaticPage(self.driver, self.PROJECT)
        project.is_title_matches("projekcie")
        project.is_new_matches()

    def test_rtd(self):
        rtd = StaticPage(self.driver, self.RTD)
        rtd.is_title_matches("to dobrze")
        rtd.is_new_matches()
    
    def test_przepisy(self):
        przepisy = StaticPage(self.driver, self.PRZEPISY)
        przepisy.is_title_matches("rzepisy")
    
    def test_faq(self):
        faq = StaticPage(self.driver, self.FAQ)
        faq.is_title_matches("zadawane pytania")
    
    def test_statystyki(self):
        stat = StaticPage(self.driver, self.STATYSTYKI)
        stat.is_title_matches("statystyki")