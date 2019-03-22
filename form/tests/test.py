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
        new.reload_on_edit()
        new.is_title_matches("owe")
        new.is_validation_empty_working()
    
    def test_new_category_other(self):
        new = pages.New(self.driver)
        new.reload_on_edit()
        new.is_title_matches("owe")
        new.is_other_comment_validation_working()

    def test_new_images(self):
        new = pages.New(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()

    def test_review(self):
        new = pages.New(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()
        new.review()
        new.update()
        new.commit()
        new.fin()
        
    def test_invalid_image(self):
        new = pages.New(self.driver)
        new.reload_on_edit()
        new.test_invalid_image()
        new.test_invalid_image_submit()

    def test_app_page(self):
        new = pages.New(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()
        new.update()
        new.commit(has_comment=False)
        new.fin()
        new.app_page()

    def test_pdf(self):
        new = pages.New(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()
        new.update()
        new.commit(has_comment=False)
        new.fin()
        url = new.app_page()
        new.check_pdf(url)
    
    def test_statements(self):
        new = pages.New(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()
        new.check_default_statements() # both true

        new.confirm()
        new.test_witness_statement(True)

        new.back('owe ')
        new.flip_witness_statement()

        new.confirm()
        new.test_witness_statement(False)

    def test_check_list(self):
        myApps = pages.MyApps(self.driver)
        myApps.check_list()

    def test_my_apps(self):
        myApps = pages.MyApps(self.driver)
        myApps.check_first(False)