import io
import os
import tempfile
import unittest
from openpyxl import Workbook

os.environ['DATA_DIR'] = tempfile.mkdtemp()
os.environ['ADMIN_PASSWORD'] = 'test-password'
from app import app, state
from parser import parse_excel


def workbook():
    book = Workbook()
    sheet = book.active
    sheet.title = 'SI-41'
    sheet['A1'] = 'SEMAINE IMPAIRE 41 - DU 5 AU 10 OCTOBRE 2026'
    sheet.append(['03', 'PIERRIC', 'OMR', 'Robot 201', 'CHAUFFEUR', 'ÉQUIPIER', '', '', 'LUNDI'])
    sheet.append(['LIVRAISON BACS', '', '', '', 'AGENT'])
    output = io.BytesIO()
    book.save(output)
    return output.getvalue()


class PlanningTests(unittest.TestCase):
    def setUp(self):
        self.client = app.test_client()
        self.client.get('/admin')
        with self.client.session_transaction() as session:
            self.csrf = session['csrf']

    def post(self, **kwargs):
        return self.client.post('/admin', data={'csrf': self.csrf, **kwargs})

    def test_parser_shifted_day_and_delivery(self):
        weeks = parse_excel(workbook())
        self.assertEqual(weeks[0]['start'], '2026-10-05')
        self.assertEqual(len(weeks[0]['days'][0]['routes']), 2)
        self.assertEqual(weeks[0]['days'][0]['routes'][0]['crew1'], 'ÉQUIPIER')

    def test_import_auth_selection_and_preservation(self):
        self.assertEqual(self.post(action='upload', file=(io.BytesIO(workbook()), 'test.xlsx')).status_code, 302)
        self.assertEqual(self.post(action='login', password='test-password').status_code, 302)
        result = self.post(action='upload', file=(io.BytesIO(workbook()), 'test.xlsx'))
        self.assertIn('1 semaines importées', result.get_data(as_text=True))
        self.post(action='select', week='2026-W41')
        self.assertEqual(self.client.get('/api/planning').json['week']['id'], '2026-W41')
        revision = state()['revision']
        self.post(action='upload', file=(io.BytesIO(b'broken'), 'test.xlsx'))
        self.assertEqual(state()['revision'], revision)
        self.assertEqual(self.client.post('/admin', data={'action':'select', 'week':'auto'}).status_code, 400)
        self.post(action='select', week='auto')
        self.assertEqual(self.client.get('/api/planning').json['mode'], 'auto')
        self.assertEqual(self.client.get('/').status_code, 200)


if __name__ == '__main__':
    unittest.main()
