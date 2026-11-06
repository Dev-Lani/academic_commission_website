"""Logs CPU and RAM once per interval while a load test runs.
   pip install psutil
   python monitor.py usage_50users.csv [--interval 1]     (Ctrl+C to stop, then it prints averages)"""
import argparse, csv, time
import psutil

ap = argparse.ArgumentParser()
ap.add_argument('out')
ap.add_argument('--interval', type=float, default=1.0)
a = ap.parse_args()

WATCH = ('httpd', 'apache', 'mysqld', 'mariadbd', 'php')
def proc_cpu():
    tot = {'web_cpu': 0.0, 'db_cpu': 0.0}
    for p in psutil.process_iter(['name']):
        n = (p.info['name'] or '').lower()
        try:
            if n.startswith(('httpd', 'apache', 'php')): tot['web_cpu'] += p.cpu_percent(None)
            elif n.startswith(('mysqld', 'mariadbd')):   tot['db_cpu']  += p.cpu_percent(None)
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            pass
    return tot

rows = []
psutil.cpu_percent(None); proc_cpu()
with open(a.out, 'w', newline='') as f:
    w = csv.writer(f)
    w.writerow(['time', 'cpu_pct', 'ram_pct', 'ram_used_mb', 'web_cpu_pct', 'db_cpu_pct'])
    try:
        while True:
            time.sleep(a.interval)
            pc = proc_cpu(); vm = psutil.virtual_memory()
            r = [time.strftime('%H:%M:%S'), psutil.cpu_percent(None), vm.percent, round(vm.used / 1048576),
                 round(pc['web_cpu'], 1), round(pc['db_cpu'], 1)]
            w.writerow(r); f.flush(); rows.append(r)
    except KeyboardInterrupt:
        pass
if rows:
    n = len(rows)
    print(f'\n{n} samples. CPU avg {sum(r[1] for r in rows)/n:.1f}% max {max(r[1] for r in rows)}% | '
          f'RAM avg {sum(r[2] for r in rows)/n:.1f}% max {max(r[2] for r in rows)}%')