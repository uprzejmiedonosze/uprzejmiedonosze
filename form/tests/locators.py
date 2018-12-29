from selenium.webdriver.common.by import By

class Locators(object):
    """A class for main page locators. All main page locators should come here"""
    MAIN      = (By.XPATH, "//a[@href='/']")
    CHANGELOG = (By.XPATH, "//a[@href='changelog.html']")
    PROJECT   = (By.XPATH, "//a[@href='projekt.html']")
    RTD       = (By.XPATH, "//a[@href='robtodobrze.html']")
    NEW       = (By.XPATH, "//a[@href='start.html']")

class SearchResultsPageLocators(object):
    """A class for search results locators. All search results locators should come here"""
    pass