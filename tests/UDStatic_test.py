import time
import unittest
from UDTest import UDTest
from pages.StaticPage import StaticPage
from selenium.webdriver.common.by import By

class UDStatic_test(UDTest):
    MAIN        = (By.XPATH, "//a[@href='/']")
    CHANGELOG   = (By.XPATH, "//a[@href='/changelog.html']")
    PROJECT     = (By.XPATH, "//a[@href='/projekt.html']")
    PRZEPISY    = (By.XPATH, "//a[@href='/przepisy.html']")
    RTD         = (By.XPATH, "//a[@href='robtodobrze.html']")
    FAQ         = (By.XPATH, "//a[@href='/faq.html']")
    START       = (By.XPATH, "//a[@href='/start.html']")
    STATYSTYKI  = (By.XPATH, "//a[@href='/statystyki.html']")
    MANDAT      = (By.XPATH, "//a[@href='/mandat.html']")
    
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