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
        main_page = pages.MainPage(self.driver)
        main_page.is_title_matches("Uprzejmie")
        main_page.is_new_matches()
    
    def test_changelog(self):
        changelog = pages.Changelog(self.driver)
        changelog.is_title_matches("istoria"),
        changelog.is_new_matches()
    
    def test_project(self):
        project = pages.Project(self.driver)
        project.is_title_matches("projekcie")
        project.is_new_matches()

    def test_rtd(self):
        rtd = pages.RTD(self.driver)
        rtd.is_title_matches("to dobrze")
        rtd.is_new_matches()
    
    def test_start(self):
        start = pages.Start(self.driver)
        start.is_title_matches("tart")

    def test_new_empty(self):
        new = pages.New(self.driver)
        new.is_title_matches("owe")
        new.is_validation_empty_working()
    
    def test_new_category_other(self):
        new = pages.New(self.driver)
        new.is_title_matches("owe")
        new.is_other_comment_validation_working()

    def test_new_images(self):
        new = pages.New(self.driver)
        new.test_context_image()
        new.test_car_image()

    def test_review(self):
        new = pages.New(self.driver)
        new.test_context_image()
        new.test_car_image()
        new.review()
        new.update()
        new.commit()
        new.fin()
    
    def test_invalid_image(self):
        new = pages.New(self.driver)
        new.test_invalid_image()
        new.test_invalid_image_submit()