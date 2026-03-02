import { Box, Text, useMantineTheme } from '@mantine/core';
import { WindowProvider, useWindow } from './WindowContext';
import { Header } from './Header';
import { Sidebar } from './Sidebar';
import { DesktopIcons } from './DesktopIcons';
import { DraggableWindow } from './DraggableWindow';
import { SampleAppContent } from '../Apps/SampleApp';
import { useCallback, useState } from 'react';
import {
    IconFolder,
    IconSettings,
    IconTerminal2,
    IconBrandDocker,
    IconActivity,
    IconDisc,
} from '@tabler/icons-react';

// Map database icon names to Tabler React components
const ICON_MAP = {
    IconFolder,
    IconSettings,
    IconTerminal2,
    IconBrandDocker,
    IconActivity,
    IconDisc,
};

const APP_COMPONENTS = {
    filemanager: () => <SampleAppContent title="File Manager" emoji="📁" />,
    settings: () => <SampleAppContent title="Settings" emoji="⚙️" />,
    terminal: () => <SampleAppContent title="Terminal" emoji="💻" />,
    docker: () => <SampleAppContent title="Docker" emoji="🐳" />,
    monitor: () => <SampleAppContent title="Monitor" emoji="📊" />,
    storage: () => <SampleAppContent title="Storage" emoji="💾" />,
};

function DesktopContent({ version, desktopApps = [], userIconPositions = {} }) {
    const theme = useMantineTheme();
    const { windows } = useWindow();
    const [savingPosition, setSavingPosition] = useState(false);

    // Handle icon position change - save to database
    const handleIconPositionChange = useCallback(async (desktopAppId, positionX, positionY) => {
        console.log('Saving position:', { desktopAppId, positionX, positionY });
        try {
            setSavingPosition(true);
            const response = await fetch(`/api/desktop-icons/${desktopAppId}/position`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({
                    position_x: positionX,
                    position_y: positionY,
                }),
            });

            console.log('Response status:', response.status);
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Failed to save icon position:', response.status, errorText);
            } else {
                const data = await response.json();
                console.log('Position saved successfully:', data);
            }
        } catch (error) {
            console.error('Error saving icon position:', error);
        } finally {
            setSavingPosition(false);
        }
    }, [desktopApps]);

    // Transform desktop apps from database to format needed by components
    const apps = desktopApps.map((app) => {
        const IconComponent = ICON_MAP[app.icon_name] || IconFolder;
        // Get user position if available - keys from Inertia are strings
        const userPosition = userIconPositions[String(app.id)];
        return {
            id: app.identifier,
            desktopAppId: app.id,
            name: app.name,
            icon: IconComponent,
            color: app.color,
            description: app.description,
            type: app.type,
            url: app.url,
            component_path: app.component_path,
            positionX: userPosition?.position_x ?? 0,
            positionY: userPosition?.position_y ?? 0,
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

export function DesktopLayout({ children, version = '1.0.0', desktopApps = [], userIconPositions = {} }) {
    return (
        <WindowProvider>
            <DesktopContent version={version} desktopApps={desktopApps} userIconPositions={userIconPositions} />
            {children}
        </WindowProvider>
    );
}
