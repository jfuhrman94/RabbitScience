#!/usr/bin/python
import time
import logging
import sqlite3
import os.path
import datetime
import json
from logging import FileHandler
from subprocess import check_call as call
from flask import *
from flup.server.fcgi import WSGIServer

# Switch for local testing
local_test = False

# Initialize the app
app = Flask('RabbitApp')

# Set up logging
logger = FileHandler('logs/RabbitApp.log')
app.logger.setLevel(logging.DEBUG)
app.logger.addHandler(logger)
app.logger.debug(u"Flask server started " + time.asctime())

# Load private info
private = {}
with open('private.txt', 'r') as f:
    for line in f:
        line = [x.strip() for x in line.split(',')]
        private[line[0]] = line[1]

app.config.update(dict(
    SECRET_KEY=private['SECRET_KEY']
))

# standard Arduino data types
dataTypes = ['LED_1']
# Standard plant table cols
plantCols = ['id','posted','img','plot','comment'] + dataTypes

keys = ['last', 'record1', 'record2', 'record3']
def _set_session_vals():
    vals = {}
    for key in keys:
        vals[key] = None
    session["vals"] = vals

def connect_db():
    rv = sqlite3.connect('plants.db')
    rv.row_factory = sqlite3.Row
    return rv

def get_db():
    if not hasattr(g, 'plants_db'):
        g.plants_db = connect_db()
    return g.plants_db

@app.teardown_appcontext
def close_db(error):
    if hasattr(g, 'plants_db'):
        g.plants_db.close()

def init_db():
    db = connect_db()
    with open('plants.sql', 'r') as f:
        db.cursor().executescript(f.read())
    db.commit()

def _get_plant_types():
    return None

@app.route("/")
def root():
    return render_template('home.html')

@app.route("/log_page")
def log_page(argDict={}):
    if not session.get('logged_in'):
        abort(401)
    log_args = {}
    for key in argDict:
        log_args[key] = argDict[key]
    log_page = request.args.get('log_page')
    if not log_page:
        if 'log_page' in argDict:
            log_page = argDict['log_page']
        else:
            log_page = 'log_home'
    if log_page == 'log_reg':
        db = get_db()
        plant_types = []
        for row in db.execute('select distinct plant_type from plants'):
            plant_types.append(row[0])
        log_args['plant_types'] = plant_types
    if log_page in ['log_view', 'log_mg', 'log_ret']:
        active_plants = []
        retired_plants = []
        db = get_db()
        for row in db.execute('select * from plants where retired is null'):
            plant = {}
            plant['id'] = str(row[0])
            plant['plant_type'] =row[1]
            plant['nickname'] = row[2]
            plant['born'] = str(row[3])
            active_plants.append(plant)
        for row in db.execute('select * from plants where retired is not null'):
            plant = {}
            plant['id'] = str(row[0])
            plant['plant_type'] =row[1]
            plant['nickname'] = row[2]
            plant['born'] = str(row[3])
            plant['retired'] = str(row[4])
            retired_plants.append(plant)
        log_args['active_plants'] = active_plants
        log_args['active_json'] = json.dumps(active_plants)
        log_args['retired_plants'] = retired_plants
        log_args['retired_json'] = json.dumps(retired_plants)
    log_page = 'log-templates/' + log_page + '.html'
    return render_template('log.html', log_page=log_page, log_args=log_args)

@app.route("/reg_plant")
def reg_plant():
    if not session.get('logged_in'):
        abort(401)
    plant_type = request.args.get('plant_type')
    if plant_type == 'new':
        plant_type = request.args.get('new_plant_type')
    nickname = request.args.get('nickname')
    db = get_db()
    db.execute('insert into plants (plant_type, nickname, born) values (?,?,?)', (plant_type, nickname, datetime.date.today()))
    for row in db.execute('select last_insert_rowid()'):
        id = 'plant_' + str(row[0])
    with app.open_resource('new_plant.sql', mode='r') as f:
        qry = f.read().replace('[[ID]]',id)
        db.cursor().executescript(qry)
    comment = request.args.get('comment')
    if comment:
        db.execute('insert into {} (posted, comment) values (?, ?)'.format(id), (int(time.time()), comment))
    db.commit()
    if not os.path.exists(('static/plants/' + id)):
        os.makedirs(('static/plants/' + id))
    flash('Registered New {}'.format(plant_type))
    argDict = {}
    argDict['log_page'] = 'log_reg'
    return log_page(argDict=argDict)

@app.route("/view_plant")
def view_plant():
    if not session.get('logged_in'):
        abort(401)
    argDict = {}
    argDict['log_page'] = 'log_view'
    argDict['entries'] = []
    db = get_db()
    id = 'plant_' + request.args.get('plant_id')
    c = db.execute('select * from {}'.format(id))
    columns = [desc[0] for desc in c.description]
    for row in db.execute('select * from {}'.format(id)):
        entry = {}
        for i, key in enumerate(columns):
            entry[key] = row[i]
        entry['comment'] = entry['comment'].replace('\n','[br]')
        argDict['entries'].append(entry)
        print "comment: "
        print entry['comment']
    return log_page(argDict=argDict)

@app.route("/mg_plant")
def mg_plant():
    if not session.get('logged_in'):
        abort(401)
    argDict = {}
    argDict['log_page'] = 'log_mg'
    argDict['dataTypes_json'] = json.dumps(dataTypes)
    db = connect_db()
    id = 'plant_' + request.args.get('plant_id')
    c = db.execute('select * from {}'.format(id))
    existing_cols = [desc[0] for desc in c.description]
    manual_cols = []
    for col in existing_cols:
        if not col in plantCols:
            manual_cols.append(col)
    argDict['manual_sources'] = manual_cols
    return log_page(argDict=argDict)

@app.route("/new_entry")
def new_entry():
    if not session.get('logged_in'):
        abort(401)
    db = connect_db()
    id = 'plant_' + request.args.get('plant_id')
    cols = []
    vals = []
    if request.args.get('save_sensor_data') == 'on':
        for col in request.args.getlist('sensor_data'):
            cols.append(str(col))
            vals.append(getSensorData(col))
    c = db.execute('select * from {}'.format(id))
    existing_cols = [desc[0] for desc in c.description]
    for col in existing_cols:
        if not col in plantCols:
            if request.args.get(col):
                cols.append(str(col))
                vals.append(float(request.args.get(col)))
    if request.args.get('new_source') == 'on':
        new_cols = request.args.getlist('col_name')
        new_vals = request.args.getlist('col_value')
        for i in range(len(new_cols)):
            new_cols[i] = str(new_cols[i])
            new_vals[i] = float(new_vals[i])
            db.execute('alter table {0} add {1} float'.format(id, new_cols[i]))
        cols += new_cols
        vals += new_vals
    if request.args.get('comment'):
        cols.append('comment')
        vals.append(request.args.get('comment'))
    cols.append('posted')
    vals.append(str(int(time.time())))
    command = 'insert into {0} {1} values '.format(id, tuple(cols))
    command = command.replace("'","")
    command += '(' + '?,'*len(cols) + ')'
    command = command.replace(',)', ')')
    print 'cmd: ' + command
    for val in vals:
        print str(val)
    db.execute(command, vals)
    db.commit()
    return redirect( url_for('log_page') + "?log_page=log_mg" )

def getSensorData(col):
    return 1234

@app.route("/configure")
def configure():
    if not session.get('logged_in'):
        abort(401)
    return render_template('configure.html')

@app.route("/data")
def data(imageref=None):
    if not session.get('logged_in'):
        abort(401)
    if not session.get('vals'):
        _set_session_vals()
    if request.args.get('record1'):
        for key in keys:
            session["vals"][key] = request.args.get(key)
        if request.args.get('record2') == 'random':
            imageref='test2.png'
        else: 
            imageref='test.png'
    return render_template('data.html', imageref=imageref, vals=session["vals"])

@app.route("/login", methods=['GET','POST'])
def login():
    error = None
    if request.method == 'POST':
        if request.form['username'] != private['USERNAME']:
            error = 'Invalid username'
        elif request.form['password'] != private['PASSWORD']:
            error = 'Invalid password'
        else:
            session['logged_in'] = True
            flash('Logged In')
            return redirect(url_for('root'))
    return render_template('login.html', error=error)

@app.route('/logout')
def logout():
    session.pop('logged_in', None)
    flash('Logged Out')
    return redirect(url_for('root'))

@app.after_request
def write_access_log(response):
    app.logger.debug(u"%s %s -> %s" % (time.asctime(), request.path, response.status_code))
    return response

if __name__ == '__main__':
    if not os.path.isfile('plants.db'):
        init_db()
    if local_test:
        # Use this for local testing w/out lighttpd
        app.logger.warn(u"Launching RabbitApp for local testing")
        app.run(host='0.0.0.0', debug=True, threaded=True)
    else:
        # Use this for production testing with lighttpd
        app.logger.debug(u"Launching RabbitApp on lighttpd")
        WSGIServer(app).run()
