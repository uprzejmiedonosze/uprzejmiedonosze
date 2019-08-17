from pages.BasePage import BasePage

class StaticPage(BasePage):
    def __init__(self, driver, address):
        BasePage.__init__(self, driver, address)