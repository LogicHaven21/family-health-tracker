#!/usr/bin/env python3
"""Run ONLY on an isolated empty test installation. Creates test data."""
import json, urllib.request, urllib.error, http.cookiejar, getpass, uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
from urllib.parse import urlsplit
raw_base=input('Isolated test URL (HTTPS, or HTTP on loopback; ending /): ').strip().rstrip('/')
parsed=urlsplit(raw_base)
loopback_http=parsed.scheme=='http' and parsed.hostname in {'127.0.0.1','localhost','::1'}
if parsed.scheme!='https' and not loopback_http: raise SystemExit('HTTPS required except for a loopback-only local test server')
if parsed.username or parsed.password or parsed.query or parsed.fragment: raise SystemExit('Use a plain test URL without credentials, query, or fragment')
base=raw_base+'/'
if input('Type TEST to authorize creating test records: ')!='TEST': raise SystemExit('Cancelled')
class Client:
 def __init__(self):
  self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); self.csrf=''
 def call(self,a,data=None,csrf=None):
  headers={};body=None
  if data is not None:
   body=json.dumps(data).encode();headers={'Content-Type':'application/json','X-CSRF-Token':self.csrf if csrf is None else csrf}
  req=urllib.request.Request(base+'api.php?action='+a,data=body,headers=headers)
  try:
   with self.opener.open(req,timeout=20) as r: return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def login(self,name,pw):
  code,state=self.call('state');assert code==200
  self.csrf=state['csrf'];assert self.call('login',{'username':name,'password':pw})[0]==200
  _,state=self.call('state');self.csrf=state['csrf']
admin=Client();admin.login('admin',getpass.getpass('Test admin password: '))
_,data=admin.call('data');assert data['patients']==[], 'STOP: test database must be empty'
viewer=Client();viewer.login('doctor',getpass.getpass('Test doctor password: '))
anon=Client();assert anon.call('data')[0]==401
assert admin.call('patient',{},csrf='bad')[0]==403
assert viewer.call('patient',{})[0]==403
assert viewer.call('doctor_reset',{})[0]==403
assert admin.call('patient',{'name':'X'*101,'sex':'female','birth_date':'1990-01-01','notes':'TEST ONLY'})[0]==422
for name,sex in [('نمونه — سارا','female'),('نمونه — رضا','male')]:
 assert admin.call('patient',{'name':name,'sex':sex,'birth_date':'1960-01-01','notes':'TEST ONLY'})[0]==200
_,data=admin.call('data');p1,p2=data['patients'];t=datetime.now(ZoneInfo('Asia/Tehran')).replace(second=0,microsecond=0)-timedelta(minutes=5)
_,viewer_data=viewer.call('data');assert {p['id'] for p in viewer_data['patients']}=={p1['id'],p2['id']}
r={'uuid':str(uuid.uuid4()),'patient_id':p1['id'],'kind':'bp','measured_at':t.strftime('%Y-%m-%dT%H:%M'),'slot':'morning','group_uuid':str(uuid.uuid4()),'systolic':120,'diastolic':80,'pulse':70,'notes':'TEST'}
assert admin.call('reading',r)[0]==200
assert admin.call('reading',r)[1]['duplicate']
assert viewer.call('reading',r)[0]==403
bad={**r,'systolic':130};assert admin.call('reading',bad)[0]==409
bad={**r,'uuid':str(uuid.uuid4()),'systolic':70};assert admin.call('reading',bad)[0]==422
second={**r,'uuid':str(uuid.uuid4()),'measured_at':(t+timedelta(minutes=1)).strftime('%Y-%m-%dT%H:%M'),'systolic':130}
assert admin.call('reading',second)[0]==200
third={**second,'uuid':str(uuid.uuid4())};assert admin.call('reading',third)[0]==422
_,data=admin.call('data');assert len(data['readings'])==2
assert all(x['patient_id']==p1['id'] for x in data['readings']);assert not any(x['patient_id']==p2['id'] for x in data['readings'])
g={'uuid':str(uuid.uuid4()),'patient_id':p2['id'],'kind':'glucose','measured_at':t.strftime('%Y-%m-%dT%H:%M'),'slot':'morning','glucose':100,'context':'fasting','fasting_hours':9,'notes':'TEST'}
assert admin.call('reading',g)[0]==200
assert admin.call('reading',g)[1]['duplicate']
record=data['readings'][0];assert viewer.call('void',{'id':record['id'],'reason':'test'})[0]==403
assert admin.call('void',{'id':record['id'],'reason':'TEST correction'})[0]==200
_,data=viewer.call('data');assert len(data['readings'])==3
assert next(x for x in data['readings'] if x['id']==record['id'])['voided_at']
assert admin.call('logout',{})[0]==200
assert admin.call('data')[0]==401
print('PASS: authentication, CSRF, read-only doctor, validation, idempotency, two-reading limit, profile isolation, glucose, audit-preserving void and logout.')
