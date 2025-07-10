#!/usr/bin/env python3
import sys, os, json, re
from pdfminer.high_level import extract_text

code = sys.argv[1] if len(sys.argv)>1 else ''
base = os.path.join(os.getcwd(),'uploads')
results = {}
for f in os.listdir(base):
    if not f.lower().endswith('.pdf'): continue
    text = extract_text(os.path.join(base,f))
    pages = text.split('\f')
    for i,p in enumerate(pages,1):
        m = re.findall(r'Ref:\s*([^/\s]+)\s*/', p)
        c = m.count(code)
        if c: results.setdefault(f,[]).append({'page':i,'count':c})
print(json.dumps(results, ensure_ascii=False))