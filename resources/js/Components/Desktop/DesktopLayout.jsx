import { Box, Text, useMantineTheme } from '@mantine/core';
import { WindowProvider, useWindow } from './WindowContext';
import { Header } from './Header';
import { Sidebar } from './Sidebar';
import { DesktopIcons } from './DesktopIcons';
import { DraggableWindow } from './DraggableWindow';
import { SampleAppContent } from '../Apps/SampleApp';
import { SettingsAppContent } from '../Apps/SettingsApp';
import { useCallback, useState } from 'react';

const APP_COMPONENTS = {
    filemanager: () => <SampleAppContent title="File Manager" emoji="📁" />,
    settings: () => <SettingsAppContent />,
    terminal: () => <SampleAppContent title="Terminal" emoji="💻" />,
    docker: () => <SampleAppContent title="Docker" emoji="🐳" />,
    monitor: () => <SampleAppContent title="Monitor" emoji="📊" />,
    storage: () => <SampleAppContent title="Storage" emoji="💾" />,
};

function DesktopContent({ version, desktopApps = [], userIconOrders = {} }) {
    const theme = useMantineTheme();
    const { windows } = useWindow();
    const [savingPosition, setSavingPosition] = useState(false);

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
                backgroundColor: theme.colors.dark[9],
                backgroundImage: 'linear-gradient(135deg, #1a1b1e 0%, #25262b 100%)',
                overflow: 'hidden',
            }}
        >
            {/* Header */}
            <Header />

            {/* Desktop Area */}
            <Box
                style={{
                    position: 'absolute',
                    top: '48px',
                    left: 0,
                    right: 0,
                    bottom: 0,
                    display: 'flex',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                {/* Sidebar with widgets */}
                <Sidebar />

                {/* Main desktop area with icons and windows */}
                <Box
                    style={{
                        flex: 1,
                        position: 'relative',
                    }}
                >
                    {/* Desktop Icons */}
                    <DesktopIcons
                        apps={apps}
                        onIconPositionChange={handleIconPositionChange}
                    />

                    {/* Windows */}
                    {windows.map((win) => {
                        const AppComponent = APP_COMPONENTS[win.appId];
                        return (
                            <DraggableWindow key={win.id} windowState={win}>
                                {AppComponent ? <AppComponent /> : <SampleAppContent title={win.title} emoji={win.icon} />}
                            </DraggableWindow>
                        );
                    })}
                </Box>
            </Box>

            {/* Footer */}
            <Text
                size="xs"
                c="dimmed"
                style={{
                    position: 'absolute',
                    bottom: '8px',
                    right: '12px',
                    zIndex: 10,
                }}
            >
                NovaNAS v{version}
            </Text>
        </Box>
    );
}

export function DesktopLayout({ children, version = '1.0.0', desktopApps = [], userIconOrders = {} }) {
    return (
        <WindowProvider>
            <DesktopContent version={version} desktopApps={desktopApps} userIconOrders={userIconOrders} />
            {children}
        </WindowProvider>
    );
}
