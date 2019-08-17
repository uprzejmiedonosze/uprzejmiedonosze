import time
import unittest
from UDTest import UDTest
from pages.MyApps import MyApps
from selenium.webdriver.common.by import By

class UDMyApps_test(UDTest):
    def test_check_list(self):
        myApps = MyApps(self.driver)
        myApps.check_list()

    def test_my_apps(self):
        myApps = MyApps(self.driver)
        myApps.check_first(False)