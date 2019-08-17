import time
import unittest
from UDTest import UDTest
from pages.MyApps import MyApps
from selenium.webdriver.common.by import By

class UDMyApps_test(UDTest):

    @classmethod
    def setUpClass(cls):
        super().setUpClass()

    @classmethod
    def tearDownClass(cls):
        super().tearDownClass()

    def setUp(self):
        super().setUp()

    def test_emp

    def test_check_list(self):
        myApps = MyApps(self.driver)
        myApps.check_list()

    def test_my_apps(self):
        myApps = MyApps(self.driver)
        myApps.check_first(False)