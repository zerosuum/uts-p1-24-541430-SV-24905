#!/usr/bin/env python3
"""Emit a successful create and a validation failure without printing credentials."""
import json
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]

def env_values():
    result = {}
    env = ROOT / '.env'
    if not env.exists():
        raise RuntimeError('Root .env missing; create it locally first.')
    for raw in env.read_text().splitlines():
        text = raw.strip()
        if not text or text.startswith('#') or '=' not in text:
            continue
        key, value = text.split('=', 1)
        result[key.strip()] = value.strip().strip('"').strip("'")
    return result


def call(method, path, body=None, token=None):
    headers = {'Accept': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    data = None
    if body is not None:
        headers['Content-Type'] = 'application/json'
        data = json.dumps(body).encode('utf-8')
    request = Request('http://127.0.0.1:8088' + path, data=data,
                      headers=headers, method=method)
    try:
        with urlopen(request, timeout=8) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        try:
            payload = json.load(error)
        except Exception:
            payload = {}
        return error.code, payload


def main():
    env = env_values()
    email, password = env.get('DEMO_EMAIL'), env.get('DEMO_PASSWORD')
    if not email or not password:
        raise RuntimeError('Set DEMO_EMAIL and DEMO_PASSWORD in the root .env')
    status, result = call('POST', '/api/login',
                          {'email': email, 'password': password})
    if status != 200 or not result.get('token'):
        raise RuntimeError('Demo login failed (status {}). Re-seed the demo account and retry.'.format(status))
    token = result['token']  # memory only; never print or write to disk
    task_id = None
    try:
        status, created = call('POST', '/api/tasks',
                               {'title': 'P10 observability test', 'first_step': 'Verify logging'}, token)
        if status != 201:
            raise RuntimeError('Task creation returned {}'.format(status))
        task_id = created['data']['id']
        print('PASS: task creation HTTP 201 -> INFO task.created')

        status, _ = call('POST', '/api/tasks',
                          {'title': '', 'status': 'invalid-status'}, token)
        if status != 422:
            raise RuntimeError('Validation should be 422, got {}'.format(status))
        print('PASS: invalid task HTTP 422 -> WARNING task.validation_failed')
    finally:
        if task_id is not None:
            status, _ = call('DELETE', '/api/tasks/{}'.format(task_id), token=token)
            if status != 200:
                print('NOTE: delete returned HTTP {}'.format(status), file=sys.stderr)
        call('POST', '/api/logout', {}, token)
    print('Finished. No password, token, or task content was printed or saved.')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, URLError, KeyError, ValueError) as exc:
        print('ERROR: {}'.format(exc), file=sys.stderr)
        sys.exit(1)
