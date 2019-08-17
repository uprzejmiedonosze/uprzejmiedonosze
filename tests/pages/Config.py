import configparser

class Config(object):
    config = configparser.ConfigParser()
    def __init__(self, file='./config.ini'):
        self.config.read(file)
        self.account = self.config['account']
        self.app = self.config['app']