#!/usr/bin/env python3
"""
Local PC Metrics Collector
Run this script on your local Windows PC to collect and send metrics to the database.
This will populate the System Resources Monitor in the admin dashboard.
"""

import time
import psutil
import mysql.connector
import os
import platform
from datetime import datetime
import subprocess
import re

# Database configuration (matches your conn.php settings)
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',  # Empty password for WAMP default
    'database': 'sinegu_db',
    'port': 3306
}

# Set this to 0 for "Main Server" or use a different ID
VPS_ID = int(os.getenv('VPS_ID', '0'))  # 0 = Main Server/Local PC
VPS_NAME = os.getenv('VPS_NAME', 'Main Server (Local PC)')

def get_system_metrics():
    """Collect current system metrics from your local PC"""
    try:
        print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Collecting system metrics...")
        
        # CPU usage (percentage) - average across all cores
        cpu_percent = psutil.cpu_percent(interval=1)
        
        # RAM usage
        memory = psutil.virtual_memory()
        ram_percent = memory.percent
        ram_total_gb = memory.total / (1024**3)
        ram_used_gb = memory.used / (1024**3)
        
        # Disk usage (Windows uses 'C:\\')
        if platform.system() == 'Windows':
            disk = psutil.disk_usage('C:\\')
        else:
            disk = psutil.disk_usage('/')
        disk_percent = (disk.used / disk.total) * 100
        disk_total_gb = disk.total / (1024**3)
        disk_used_gb = disk.used / (1024**3)
        
        # Network latency
        latency = get_network_latency()
        
        # System uptime
        boot_time = datetime.fromtimestamp(psutil.boot_time())
        uptime_seconds = (datetime.now() - boot_time).total_seconds()
        uptime_days = int(uptime_seconds // 86400)
        uptime_hours = int((uptime_seconds % 86400) // 3600)
        uptime_minutes = int((uptime_seconds % 3600) // 60)
        uptime_str = f"{uptime_days}d {uptime_hours}h {uptime_minutes}m"
        
        metrics = {
            'cpu_percent': round(cpu_percent, 2),
            'ram_percent': round(ram_percent, 2),
            'ram_total_gb': round(ram_total_gb, 2),
            'ram_used_gb': round(ram_used_gb, 2),
            'disk_percent': round(disk_percent, 2),
            'disk_total_gb': round(disk_total_gb, 2),
            'disk_used_gb': round(disk_used_gb, 2),
            'latency_ms': latency,
            'uptime': uptime_str,
            'online': True
        }
        
        print(f"  CPU: {metrics['cpu_percent']}%")
        print(f"  RAM: {metrics['ram_percent']}% ({metrics['ram_used_gb']:.1f}GB / {metrics['ram_total_gb']:.1f}GB)")
        print(f"  Disk: {metrics['disk_percent']}% ({metrics['disk_used_gb']:.1f}GB / {metrics['disk_total_gb']:.1f}GB)")
        print(f"  Latency: {metrics['latency_ms']}ms")
        print(f"  Uptime: {metrics['uptime']}")
        
        return metrics
    except Exception as e:
        print(f"Error collecting metrics: {e}")
        return None

def get_network_latency():
    """Get network latency by pinging Google DNS"""
    try:
        if platform.system() == 'Windows':
            # Windows ping format
            result = subprocess.run(
                ['ping', '-n', '1', '8.8.8.8'],
                capture_output=True,
                text=True,
                timeout=5
            )
            if result.returncode == 0:
                # Windows format: time=12ms or time<1ms
                match = re.search(r'time[<=](\d+)', result.stdout)
                if match:
                    return round(float(match.group(1)), 0)
        else:
            # Linux/Mac ping format
            result = subprocess.run(
                ['ping', '-c', '1', '8.8.8.8'],
                capture_output=True,
                text=True,
                timeout=5
            )
            if result.returncode == 0:
                match = re.search(r'time=(\d+\.?\d*)', result.stdout)
                if match:
                    return round(float(match.group(1)), 0)
    except Exception as e:
        print(f"Error getting latency: {e}")
    
    return 10.0

def save_metrics_to_db(metrics):
    """Save metrics to database"""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        # Insert or update metrics
        query = """
        INSERT INTO vps_metrics 
        (vps_id, vps_name, cpu_percent, ram_percent, disk_percent, latency_ms, uptime, online, updated_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW())
        ON DUPLICATE KEY UPDATE
        cpu_percent = VALUES(cpu_percent),
        ram_percent = VALUES(ram_percent),
        disk_percent = VALUES(disk_percent),
        latency_ms = VALUES(latency_ms),
        uptime = VALUES(uptime),
        online = VALUES(online),
        updated_at = NOW()
        """
        
        values = (
            VPS_ID,
            VPS_NAME,
            metrics['cpu_percent'],
            metrics['ram_percent'],
            metrics['disk_percent'],
            metrics['latency_ms'],
            metrics['uptime'],
            metrics['online']
        )
        
        cursor.execute(query, values)
        conn.commit()
        
        print(f"✓ Metrics saved to database successfully!")
        print()
        
        cursor.close()
        conn.close()
        return True
        
    except mysql.connector.Error as e:
        print(f"✗ Database error: {e}")
        print("  Make sure:")
        print("  1. MySQL is running (WAMP)")
        print("  2. Database 'sinegu_db' exists")
        print("  3. Table 'vps_metrics' exists (run create_vps_metrics_table.sql)")
        print("  4. MySQL credentials are correct")
        return False
    except Exception as e:
        print(f"✗ Error saving metrics: {e}")
        return False

def check_refresh_request():
    """Check if a refresh has been requested from the web interface via signal file"""
    try:
        # Check for signal file
        script_dir = os.path.dirname(os.path.abspath(__file__))
        signal_file = os.path.join(script_dir, f'refresh_metrics_{VPS_ID}.signal')
        
        if os.path.exists(signal_file):
            # Signal file exists, remove it and return True
            os.remove(signal_file)
            return True
        
        return False
    except Exception as e:
        print(f"Error checking refresh request: {e}")
        return False

def main():
    """Main function - collect and save metrics continuously"""
    print("=" * 60)
    print(f"Local PC Metrics Collector")
    print(f"VPS ID: {VPS_ID} | Name: {VPS_NAME}")
    print("=" * 60)
    print()
    
    # Test database connection first
    try:
        test_conn = mysql.connector.connect(**DB_CONFIG)
        test_conn.close()
        print("✓ Database connection successful")
    except Exception as e:
        print(f"✗ Database connection failed: {e}")
        print("\nPlease check:")
        print("  1. WAMP MySQL is running")
        print("  2. Database credentials in the script")
        print("  3. Database 'sinegu_db' exists")
        return
    
    print()
    print("Starting continuous monitoring...")
    print("Collecting metrics every 10 minutes")
    print("Listening for refresh requests every 5 seconds")
    print("Press Ctrl+C to stop")
    print("-" * 60)
    print()
    
    last_collection = 0
    collection_interval = 600  # 10 minutes in seconds
    refresh_check_interval = 5  # Check for refresh every 5 seconds
    
    # Run continuously
    while True:
        try:
            current_time = time.time()
            
            # Check if refresh was requested
            if check_refresh_request():
                print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] 🔄 Refresh requested! Collecting metrics now...")
                metrics = get_system_metrics()
                if metrics:
                    save_metrics_to_db(metrics)
                    last_collection = current_time
                else:
                    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] ✗ Failed to collect metrics")
                    print()
                continue
            
            # Check if it's time for regular collection
            time_since_last = current_time - last_collection
            if time_since_last >= collection_interval:
                print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Collecting metrics (scheduled)...")
                metrics = get_system_metrics()
                if metrics:
                    save_metrics_to_db(metrics)
                    last_collection = current_time
                else:
                    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] ✗ Failed to collect metrics")
                    print()
            
            # Wait before next check (30 seconds)
            time.sleep(refresh_check_interval)
            
        except KeyboardInterrupt:
            print("\n\n" + "=" * 60)
            print("Stopping metrics collector...")
            print("=" * 60)
            break
        except Exception as e:
            print(f"\n[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] ✗ Unexpected error: {e}")
            print("Retrying in 1 minute...")
            print()
            time.sleep(60)  # Wait 1 minute before retrying

if __name__ == "__main__":
    import sys
    
    # Check if --once flag is provided for single run
    if '--once' in sys.argv:
        print("Running once (single collection)...")
        print()
        try:
            test_conn = mysql.connector.connect(**DB_CONFIG)
            test_conn.close()
            print("✓ Database connection successful")
        except Exception as e:
            print(f"✗ Database connection failed: {e}")
            exit(1)
        
        metrics = get_system_metrics()
        if metrics:
            save_metrics_to_db(metrics)
            print("Done! Check your admin dashboard to see the metrics.")
        else:
            print("✗ Failed to collect metrics")
    else:
        # Run continuously by default
        main()

