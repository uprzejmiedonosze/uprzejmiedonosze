import logging
from logging.handlers import SysLogHandler

logger = logging.getLogger('uvicorn.error')
logger.setLevel(logging.DEBUG)
handler = SysLogHandler(
    facility=SysLogHandler.LOG_DAEMON
    )
logger.addHandler(handler)
