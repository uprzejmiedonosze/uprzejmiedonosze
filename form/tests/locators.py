from selenium.webdriver.common.by import By

class Locators(object):
    """A class for main page locators. All main page locators should come here"""
    MAIN        = (By.XPATH, "//a[@href='/']")
    CHANGELOG   = (By.XPATH, "//a[@href='changelog.html']")
    PROJECT     = (By.XPATH, "//a[@href='projekt.html']")
    RTD         = (By.XPATH, "//a[@href='robtodobrze.html']")
    START       = (By.XPATH, "//a[@href='start.html']")
    NEW         = (By.XPATH, "//a[@href='nowe-zgloszenie.html']")

    LOGIN_BTN   = (By.XPATH, "//button[contains(@class, 'firebaseui-idp-button')]")
    LOGIN_EMAIL = (By.XPATH, "//input[@id='identifierId']")
    LOGIN_NEXT  = (By.ID,    "identifierNext")
    LOGIN_PASWD = (By.XPATH, "//input[@name='password']")
    LOGIN_FIN   = (By.ID,    "passwordNext")

    START_RULES = (By.ID,    "rules")

class SearchResultsPageLocators(object):
    """A class for search results locators. All search results locators should come here"""
    pass