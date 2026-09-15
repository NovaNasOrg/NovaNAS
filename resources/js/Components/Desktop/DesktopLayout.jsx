import { Box, Text, useMantineTheme } from '@mantine/core';
import { WindowProvider, useWindow } from './WindowContext';
import { Header } from './Header';
import { Sidebar } from './Sidebar';
import { DesktopIcons } from './DesktopIcons';
import { DraggableWindow } from './DraggableWindow';
import { SettingsAppContent } from '../Apps/SettingsApp';
import { TerminalAppContent } from '../Apps/TerminalApp';
import { FirewallAppContent } from '../Apps/FirewallApp';
import { StorageAppContent } from '../Apps/StorageApp';
import { DockerAppContent } from '../Apps/DockerApp';
import { ApplicationsAppContent } from '../Apps/ApplicationsApp';
import { UpdatesAppContent } from '../Apps/UpdatesApp';
import { FileManagerAppContent } from '../Apps/FileManagerApp';
import { MonitorAppContent } from '../Apps/MonitorApp';
import { SupportAppContent } from '../Apps/SupportApp';
import { BackupAppContent } from '../Apps/BackupApp';
import { LogsAppContent } from '../Apps/LogsApp';
import { ReverseProxyAppContent } from '../Apps/ReverseProxyApp';
import { useCallback, useState, useEffect } from 'react';
import { useIsMobile } from './useIsMobile';

const APP_COMPONENTS = {
    filemanager: () => <FileManagerAppContent />,
    settings: () => <SettingsAppContent />,
    terminal: () => <TerminalAppContent />,
    docker: () => <DockerAppContent />,
    monitor: (windowId) => <MonitorAppContent windowId={windowId} />,
    storage: () => <StorageAppContent />,
    firewall: () => <FirewallAppContent />,
    applications: () => <ApplicationsAppContent />,
    updates: () => <UpdatesAppContent />,
    support: () => <SupportAppContent />,
    backup: () => <BackupAppContent />,
    logs: () => <LogsAppContent />,
    'reverse-proxy': () => <ReverseProxyAppContent />,
};

function DesktopContent({ version, initialDesktopApps = [], initialUserIconOrders = {} }) {
    const theme = useMantineTheme();
    const { windows } = useWindow();
    const isMobile = useIsMobile();
    const [savingPosition, setSavingPosition] = useState(false);
    const [desktopApps, setDesktopApps] = useState(initialDesktopApps);
    const [userIconOrders, setUserIconOrders] = useState(initialUserIconOrders);
    const [sidebarOpened, setSidebarOpened] = useState(false);

    const refreshDesktop = useCallback(async () => {
        try {
            const response = await fetch('/api/desktop-apps');
            if (response.ok) {
                const data = await response.json();
                setDesktopApps(data.desktopApps);
                setUserIconOrders(data.userIconOrders);
            }
        } catch {
            // silently fail
        }
    }, []);

    // Handle icon order change - save to database
    const handleIconPositionChange = useCallback(async (orders) => {
        try {
            setSavingPosition(true);
            const response = await fetch(`/api/desktop-icons/order`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({
                    orders: orders,
                }),
            });

            const data = await response.json();
            console.log('Order saved successfully:', response.ok, data);
        } catch (error) {
            console.error('Error saving icon order:', error);
        } finally {
            setSavingPosition(false);
        }
    }, []);

    // Transform desktop apps from database to format needed by components
    const apps = desktopApps.map((app) => {
        const iconName = app.icon_name;
        // Get user order if available - keys from Inertia are strings
        const userOrder = userIconOrders[String(app.id)];
        // Map database color names to valid Mantine theme colors or pass through HEX colors
        const colorMap = {
            blue: 'blue',
            gray: 'gray',
            dark: 'dark',
            green: 'green',
            orange: 'orange',
            violet: 'violet',
            red: 'red',
            yellow: 'yellow',
            cyan: 'cyan',
            teal: 'teal',
        };
        // If the color starts with #, it's a HEX color - pass it through directly
        const mappedColor = app.color.startsWith('#') ? app.color : (colorMap[app.color] || 'blue');
        return {
            id: app.identifier,
            desktopAppId: app.id,
            name: app.name,
            iconName: iconName,
            color: mappedColor,
            description: app.description,
            type: app.type,
            url: app.url,
            iconPath: app.icon_path,
            component_path: app.component_path,
            order: userOrder?.order ?? (app.id - 1), // Use database order or fallback to app id as order
        };
    });

    return (
        <Box
            style={{
                position: 'fixed',
                top: 0,
                left: 0,
                right: 0,
                bottom: 0,
                backgroundColor: '#0e0e12',
                backgroundImage: `
                    radial-gradient(ellipse 80% 60% at 20% 10%, rgba(30, 58, 95, 0.2) 0%, transparent 60%),
                    radial-gradient(ellipse 60% 50% at 80% 90%, rgba(45, 20, 60, 0.15) 0%, transparent 60%),
                    linear-gradient(160deg, #12121a 0%, #1a1a24 40%, #16161e 100%)
                `,
                overflow: 'hidden',
            }}
        >
            {/* Header */}
            <Header sidebarOpened={sidebarOpened} onToggleSidebar={() => setSidebarOpened((o) => !o)} isMobile={isMobile} />

            {/* Desktop Area */}
            <Box
                style={{
                    position: 'absolute',
                    top: isMobile ? '64px' : '76px',
                    left: isMobile ? '6px' : '12px',
                    right: isMobile ? '6px' : '12px',
                    bottom: isMobile ? '6px' : '12px',
                    display: 'flex',
                    gap: isMobile ? '0' : '12px',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                {/* Sidebar with widgets - hidden on mobile (rendered as drawer in Sidebar) */}
                {!isMobile && <Sidebar />}

                {/* Main desktop area with icons and windows */}
                <Box
                    style={{
                        flex: 1,
                        position: 'relative',
                        overflow: 'hidden',
                    }}
                >
                    {/* Desktop Icons */}
                    <DesktopIcons
                        apps={apps}
                        onIconPositionChange={handleIconPositionChange}
                    />

                    {/* Windows */}
                    {windows.map((win) => {
                        if (win.appId === 'applications') {
                            return (
                                <DraggableWindow key={win.id} windowState={win}>
                                    <ApplicationsAppContent onDesktopChange={refreshDesktop} />
                                </DraggableWindow>
                            );
                        }
                        const AppComponent = APP_COMPONENTS[win.appId];
                        return (
                            <DraggableWindow key={win.id} windowState={win}>
                                <AppComponent windowId={win.id} />
                            </DraggableWindow>
                        );
                    })}
                </Box>
            </Box>

            {/* Footer */}
            {!isMobile && (
                <Text
                    size="xs"
                    c="dimmed"
                    style={{
                        position: 'absolute',
                        bottom: '20px',
                        right: '24px',
                        zIndex: 10,
                    }}
                >
                    NovaNAS v{version}
                </Text>
            )}

            {/* Mobile Sidebar Drawer */}
            {isMobile && (
                <Sidebar
                    opened={sidebarOpened}
                    onClose={() => setSidebarOpened(false)}
                    isMobile={isMobile}
                />
            )}
        </Box>
    );
}

export function DesktopLayout({ children, version = '1.0.0', desktopApps = [], userIconOrders = {} }) {
    return (
        <WindowProvider>
            <DesktopContent version={version} initialDesktopApps={desktopApps} initialUserIconOrders={userIconOrders} />
            {children}
        </WindowProvider>
    );
}
