###############################
#   RabbitScience             #
#   DatabaseDaemon.py         #
#   Written by Josh Fuhrman   #
###############################

#############
#   Setup   #
#############

# Set imports
import logging
import socket
import time
import sqlite3
import sys
from subprocess import check_call as call
from sys import path
from os.path import isfile
from random import random

# Set references
VERSION = "v0.1.0"
ROOT_FOLDER = "/srv/dev/{0}/".format(VERSION)
LOG_FILENAME = ROOT_FOLDER + "logs/database.log"
LOG_LEVEL = logging.DEBUG
APP_ID = int(10000 * random())
SQL_DATABASE = ROOT_FOLDER + "stateful.db"
RRD_NAME = "sensor.rrd"
RRD_DATABASE = ROOT_FOLDER + RRD_NAME
YUN_SERVER_PORT = 5678
UPDATE_TIMEOUT = 30 # seconds
INIT_STATE = 99

# Set client command standardizations
YUN_STATUS = 'YUN_STATUS'
ARDUINO_STATUS = 'ARDUINO_STATUS'
STATE_ID = 'STATE_ID'
UPDATE = 'UPDATE'
DATA_TIMESTAMP = 'DATA_TIMESTAMP'
LED_99 = 'LED_99'

# Define custome exceptions
class TimeoutError(Exception):
    pass
class StateError(Exception):
    pass
class ArduinoError(Exception):
    pass

# Setup logging
logger = logging.getLogger(__name__)
logger.setLevel(LOG_LEVEL)
handler = logging.FileHandler(LOG_FILENAME)
formatter = logging.Formatter('{0}: %(asctime)s: %(levelname)-8s: %(message)s'.format(APP_ID))
handler.setFormatter(formatter)
logger.addHandler(handler)
logger.info('Database Daemon Starting')

# Class to capture stdout and sterr in the log
class SilentLogger(object):
    def __init__(self, logger, level):
        self.logger = logger
        self.level = level

    def write(self, message):
        # Only log if there is a message (not just a new line)
        if message.rstring() != "":
            self.logger.log(self.level, message.rstrip())

# Replace stdout with logging to file at INFO level
sys.stdout = SilentLogger(logger, logging.INFO)
# Replace stderr with logging to file at ERROR level
sys.stderr = SilentLogger(logger, logging.ERROR)

# Setup bridgclient
path.insert(0, '/usr/lib/python2.7/bridge/')
from bridgeclient import BridgeClient as bridgeclient
client = bridgeclient()

# Load or set up the stateful database
con = sqlite3.connect(SQL_DATABASE)
con.isolation_level = None # Set database connection to autocommit
c = con.cursor()
try:
    c.execute("CREATE TABLE state (Posted INT, StateID INT)")
    c.execute("INSERT INTO state (Posted, StateID) VALUES (?,?)", (int(time.time()), INIT_STATE))
    c.execute("CREATE TABLE update_queue (Posted INT, StateID INT)")
except:
    pass

# Set up the sensor database if needed
if not isfile(RRD_DATABASE):
    # TODO: Update the DSs and RRAs with the real data and desired storages
    DSs = ['DS:LED_99:GAUGE:10:0:U']
    RRAs = ['RRA:AVERAGE:0.5:1:120', 'RRA:AVERAGE:0.5:12:60']
    creat_params = ['rrdtool', 'create', RRD_NAME, '--start', str(int(time.time())), '--step', '5'] + DSs + RRAs
    call(creat_params)

########################
#   Helper Functions   #
########################

def transition_state(row, table, timeout=UPDATE_TIMEOUT):
    logger.debug('transition_state:')
    Posted = row[0]
    NewStateID = row[1]
    OldStateID = int(client.get(STATE_ID))
    try:
        logger.debug('  try')
        post_transition(Posted, NewStateID, OldStateID)
        wait_transition(timeout)
        clean_transition(Posted, table)
    except StateError:
        logger.debug('  StateError')
        if table == 'state':
            wait_arduino(timeout=120)
    except TimeoutError:
        logger.debug('  TimeoutError')
        raise TimeoutError

def post_transition(Posted, NewStateID, OldStateID):
    logger.debug('post_transition')
    if NewStateID == OldStateID:
        logger.debug('No state change required')
        raise StateError
    client.put(STATE_ID, str(NewStateID))
    client.put(UPDATE, "True") # Put this last so arduino has all state info available
    logger.info('Posting state change to bridge at {0}'.format(Posted))
    logger.info('Updating state ID from {0} to {1}'.format(OldStateID, NewStateID))

def wait_transition(timeout=UPDATE_TIMEOUT):
    logger.debug('wait_transition')
    timeout = time.time() + timeout
    # Wait for arduino to update state
    while True:
        logger.debug('  while')
        if (client.get(UPDATE) == "False"):
            logger.info('Arduino state successfully updated')
            return None
        elif time.time() >= timeout:
            raise TimeoutError
        time.sleep(0)
    
def clean_transition(Posted, table):
    logger.debug('clean_transition')
    if table == 'update_queue':
        c.execute("DELETE FROM {} WHERE Posted<=?".format(table),(Posted,))
    else:
        c.execute("DELETE FROM {} WHERE Posted<?".format(table),(Posted,))
    logger.info('Cleaned up table {0}'.format(table))

def wait_arduino(checkAwake=False, timeout=UPDATE_TIMEOUT):
    logger.debug('wait_arduino')
    timeout = time.time() + timeout
    while True:
        logger.debug('  while')
        status = client.get(ARDUINO_STATUS)
        logger.debug('  Status: ' + status)
        if (not status) or (status == 'LOADING'):
            if (checkAwake):
                logger.debug('Arduino awake')
                return None
            logger.debug('sleeping')
            time.sleep(1)
        elif status == 'RUNNING':
            logger.info('Arduino is running')
            return None
        elif time.time() >= timeout:
            raise TimeoutError

def yun_startup(timeout=120):
    # Load last state into arduino
    logger.info('Loading last state into arduino')
    c.execute("SELECT * FROM state ORDER BY Posted DESC")
    row = c.fetchone()
    wait_arduino(checkAwake=True, timeout=timeout)
    if (not client.get(STATE_ID)):
        client.put(YUN_STATUS, 'LOADING')            
        client.put(STATE_ID, '0')
    transition_state(row, 'state')
    # Update Yun status
    logger.info('Database daemon running')
    client.put(YUN_STATUS, 'RUNNING')

####################
#   Main Program   #
####################

initSetup = True
# Error handling daemon loop
while True:
    try:
        # Start Yun and sync with Arduino
        if initSetup:
            yun_startup()
            initSetup = False
        # Main daemon loop
        lastTimestamp = int(time.time()) # TODO: Should this be initialized another way?
        while True:
            logger.debug('Main daemon loop')
            # Verify Arduino is running
            if (client.get(ARDUINO_STATUS) != "RUNNING"):
                raise ArduinoError
            # Check for state update request
            c.execute("SELECT * FROM update_queue ORDER BY Posted DESC")
            row = c.fetchone()
            if row:
                logger.debug('Update row found')
                # Updated arduino state
                transition_state(row, 'update_queue')
            # Check for new data, update RRD
            dataTimestamp = int(client.get(DATA_TIMESTAMP))
            if dataTimestamp > lastTimestamp:
                logger.debug('New data timestamp found')
                lastTimestamp = dataTimestamp
                logger.debug('LED_99: ' + str(dataTimestamp) + ' ' + str(client.get(LED_99)))
                call(['rrdtool', 'update', RRD_NAME, '{0}:{1}'.format(dataTimestamp, client.get(LED_99))]) # TODO: Update with correct data
    except TimeoutError:
        pass
        logger.error('Timeout reached updating Arduino state')
        # TODO: Handle a TimeoutError
    except ArduinoError:
        logger.error('ArduinoError: Attempting to reconnect')
        yun_startup(timeout=300)
    except Exception, err:
        # Update Yun status & log
        client.put(YUN_STATUS, 'ERROR')
        logger.error('General exception caught. Retrying daemon loop')
        logger.error(Exception)
        logger.error(err)