import unittest
from selenium import webdriver
import pages
import time
import os

class UDTestStatic(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        profile = webdriver.FirefoxProfile('/Users/szn/Sites/uprzejmiedonosze.net/form/tests/selenium.ff-profile')
        cls.driver = webdriver.Firefox(firefox_profile=profile,
            firefox_binary='/Applications/Firefox.app/Contents/MacOS/firefox-bin')

        cls.driver.implicitly_wait(2)
        cls.driver.set_window_size(500, 900)
        cls.driver.get('http://staging.uprzejmiedonosze.net')

    def test_01_ssl(self):
        assert "https://" in self.driver.current_url

    def test_02_main_page(self):
        main_page = pages.MainPage(self.driver)
        main_page.is_title_matches("Uprzejmie")
        main_page.is_new_matches()
    
    def test_03_changelog(self):
        changelog = pages.Changelog(self.driver)
        changelog.is_title_matches("historia"),
        changelog.is_new_matches()
        changelog.click_main()
    
    def test_04_project(self):
        project = pages.Project(self.driver)
        project.is_title_matches("projekcie")
        project.is_new_matches()
        project.click_main()

    def test_05_rtd(self):
        rtd = pages.RTD(self.driver)
        rtd.is_title_matches("to dobrze")
        rtd.is_new_matches()
        rtd.click_main()
    
    def test_06_start(self):
        start = pages.Start(self.driver)
        start.is_title_matches("tart")

    def test_07_new(self):
        new = pages.New(self.driver)
        new.is_title_matches("Nowe")
        new.is_validation_empty_working()
        new.is_other_comment_validation_working()
        new.test_context_image()
        new.test_car_image()
        new.review()
        new.update()
        new.commit()
        new.fin()

    @classmethod
    def tearDownClass(cls):
        cls.driver.quit()