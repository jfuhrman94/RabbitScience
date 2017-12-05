#!/usr/bin/python
import time
import logging
from logging import FileHandler
from subprocess import check_call as call
from flask import *
from flup.server.fcgi import WSGIServer

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

keys = ['last', 'record1', 'record2', 'record3']
def _set_session_vals():
    vals = {}
    for key in keys:
        vals[key] = None
    session["vals"] = vals

@app.route("/")
def root():
    return render_template('home.html')

@app.route("/log")
def log_page():
    if not session.get('logged_in'):
        abort(401)
    log_args = {}
    log_page = request.args.get('log_page')
    if not log_page:
        log_page = 'log_home'
    if log_page == 'log_reg':
        log_args['plant_types'] = ['roses','weed']
    log_page = 'log-templates/' + log_page + '.html'
    print log_page
    return render_template('log.html', log_page=log_page, log_args=log_args)

@app.route("/reg_plant")
def reg_plant():
    return None

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
            flash('You were logged in')
            return redirect(url_for('root'))
    return render_template('login.html', error=error)

@app.route('/logout')
def logout():
    session.pop('logged_in', None)
    flash('You were logged out')
    return redirect(url_for('root'))

@app.after_request
def write_access_log(response):
    app.logger.debug(u"%s %s -> %s" % (time.asctime(), request.path, response.status_code))
    return response

if __name__ == '__main__':
    # Use this for local testing w/out lighttpd
    #app.logger.warn(u"Launching RabbitApp for local testing")
    #app.run(host='0.0.0.0', debug=True, threaded=True)
    
    # Use this for production testing with lighttpd
    app.logger.debug(u"Launching RabbitApp on lighttpd")
    WSGIServer(app).run()
