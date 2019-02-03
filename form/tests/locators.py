from selenium.webdriver.common.by import By

class Locators(object):
    """A class for main page locators. All main page locators should come here"""
    MAIN        = (By.XPATH, "//a[@href='/']")
    CHANGELOG   = (By.XPATH, "//a[@href='changelog.html']")
    PROJECT     = (By.XPATH, "//a[@href='projekt.html']")
    RTD         = (By.XPATH, "//a[@href='robtodobrze.html']")
    START       = (By.XPATH, "//a[@href='start.html']")
    NEW         = (By.XPATH, "//a[@href='nowe-zgloszenie.html']")
    MYAPPS      = (By.XPATH, "//a[@href='moje-zgloszenia.html']")

    CONTENT     = (By.CSS_SELECTOR, "div.ui-content")
    
    BTN_LEFT    = (By.CSS_SELECTOR, "a.ui-btn-left")

    LOGIN_BTN   = (By.XPATH, "//button[contains(@class, 'firebaseui-idp-button')]")
    LOGIN_EMAIL = (By.XPATH, "//input[@id='identifierId']")
    LOGIN_NEXT  = (By.ID,    "identifierNext")
    LOGIN_PASWD = (By.XPATH, "//input[@name='password']")
    LOGIN_FIN   = (By.ID,    "passwordNext")

    START_RULES = (By.ID,    "rules")

    NEW_SUBMIT  = (By.ID,    "form-submit")
    NEW_ADDRESS = (By.ID,    "address")
    NEW_PLATEID = (By.ID,    "plateId")
    NEW_COMMENT = (By.ID,    "comment")
    NEW_IMAGE1  = (By.CSS_SELECTOR, "div.ui-block-a > div.image-upload")
    NEW_IMAGE2  = (By.CSS_SELECTOR, "div.ui-block-b > div.image-upload")
    NEW_IIMAGE1 = (By.ID,    "contextImage")
    NEW_IIMAGE2 = (By.ID,    "carImage")
    NEW_CAT0    = (By.XPATH, "//label[@for='0']")
    NEW_ADD_HINT= (By.ID,    "addressHint")
    NEW_PLATEIMG= (By.ID,    "plateImage")

    CONFIRM_TEXT= (By.CSS_SELECTOR, "div.ui-content > div.ui-body")

    MYAPPS_FIRST= (By.CSS_SELECTOR, "div.application")
    MYAPPS_EXPAND= (By.CSS_SELECTOR, "div.application a")
    MYAPPS_FIRSTL= (By.XPATH, "//a[contains(@href, 'ud-')]")

    APP_PDF_LINK= (By.XPATH, "//a[contains(@href, '.pdf')]")

class SearchResultsPageLocators(object):
    """A class for search results locators. All search results locators should come here"""
    pass