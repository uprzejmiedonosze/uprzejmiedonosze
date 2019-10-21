import time
import unittest
from UDTest import UDTest
from pages.NewApplication import NewApplication
from selenium.webdriver.common.by import By

class UDNew_test(UDTest):
    def test_new_empty(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.is_title_matches("owe")
        new.is_validation_empty_working()
    
    def test_new_category_other(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.is_title_matches("owe")
        new.is_other_comment_validation_working()

    def test_new_images(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()

    def test_review(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()
        new.review()
        new.update()
        new.commit()
        new.fin()
        
    def test_invalid_image(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.test_invalid_image()
        new.test_invalid_image_submit()

    def test_app_page(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()
        new.update()
        new.commit(has_comment=False)
        new.fin()
        new.app_page()

    def test_pdf(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.test_context_image()
        new.test_car_image()
        new.update()
        new.commit(has_comment=False)
        new.fin()
        url = new.app_page()
        new.check_pdf(url)
    
    def test_statements(self):
        new = NewApplication(self.driver)
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

    def test_invalid_image(self):
        new = NewApplication(self.driver)
        new.reload_on_edit()
        new.test_invalid_image()
        new.test_invalid_image_submit()
