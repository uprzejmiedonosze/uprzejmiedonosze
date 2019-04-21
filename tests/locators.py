from selenium.webdriver.common.by import By

class Locators(object):
    """A class for main page locators. All main page locators should come here"""
    MAIN        = (By.XPATH, "//a[@href='/']")
    CHANGELOG   = (By.XPATH, "//a[@href='/changelog.html']")
    PROJECT     = (By.XPATH, "//a[@href='/projekt.html']")
    RTD         = (By.XPATH, "//a[@href='robtodobrze.html']")
    START       = (By.XPATH, "//a[@href='/start.html']")
    NEW         = (By.XPATH, "//a[@href='nowe-zgloszenie.html']")
    MYAPPS      = (By.XPATH, "//a[@href='/moje-zgloszenia.html']")
    MAIN_MENU   = (By.ID,    "menuToggle")

    CONTENT     = (By.CSS_SELECTOR, "div.ui-content")
    
    BTN_LEFT    = (By.CSS_SELECTOR, "a.ui-btn-left")

    LOGIN_BTN   = (By.XPATH, "//button[contains(@class, 'firebaseui-idp-button')]")
    LOGIN_EMAIL = (By.XPATH, "//input[@id='identifierId']")
    LOGIN_NEXT  = (By.ID,    "identifierNext")
    LOGIN_PASWD = (By.XPATH, "//input[@name='password']")
    LOGIN_FIN   = (By.ID,    "passwordNext")

    START_RULES = (By.ID,    "rules")

    NEW_SUBMIT  = (By.ID,    "form-submit")
    NEW_ADDRESS = (By.ID,    "lokalizacja")
    NEW_PLATEID = (By.ID,    "plateId")
    NEW_COMMENT = (By.ID,    "comment")
    NEW_IMAGE1  = (By.CSS_SELECTOR, "div.ui-block-a.image-upload")
    NEW_IMAGE2  = (By.CSS_SELECTOR, "div.ui-block-b.image-upload")
    NEW_IIMAGE1 = (By.ID,    "contextImage")
    NEW_IIMAGE2 = (By.ID,    "carImage")
    NEW_CAT0    = (By.XPATH, "//label[@for='0']")
    NEW_ADD_HINT= (By.ID,    "addressHint")
    NEW_PLATEIMG= (By.ID,    "plateImage")
    NEW_CLEANUP = (By.CSS_SELECTOR, "a.cleanup")
    NEW_EXPOSE  = (By.ID,    "exposeData")
    NEW_EXPOSED = (By.XPATH, '//input[@id="exposeData"]/..')
    NEW_WITNESS = (By.ID,    "witness")
    NEW_WITNESSD= (By.XPATH, '//input[@id="witness"]/..')
    NEW_PRECISE = (By.ID,    "dtPrecise")
    NEW_DP      = (By.ID,    "dp")
    NEW_HP      = (By.ID,    "hp")

    CONFIRM_TEXT= (By.CSS_SELECTOR, "div.ui-content > div.ui-body")

    MYAPPS_FIRST= (By.CSS_SELECTOR, "div.application")
    MYAPPS_EXPAND= (By.CSS_SELECTOR, "div.application")
    MYAPPS_FIRSTL= (By.XPATH, "//a[contains(@href, 'ud-')]")
    MYAPPS_FIRSTL= (By.CSS_SELECTOR, "div.application .images a")

    APP_PDF_LINK= (By.XPATH, "//a[contains(@href, '.pdf')]")

class SearchResultsPageLocators(object):
    """A class for search results locators. All search results locators should come here"""
    pass