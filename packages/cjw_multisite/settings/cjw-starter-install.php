<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: Exponential
// SOFTWARE RELEASE: 6.0.x
// COPYRIGHT NOTICE: Copyright (C) 1998 - 2026 7x
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//
// The steps of the CJW Multisite installer that install the starter content of
// cjw_multisite_democontent (the design starter of the cjw_themes_jumper
// extension), around what the kernel's package handler does:
//
//   before the packages  cjwLinkStarterFilesFromExtension(): the images and files
//                        of the content are not in the package; they are in
//                        extension/cjw_themes_jumper/share/content-images and
//                        content-files, and are linked into the package's
//                        simplefiles/ for the time of the install
//   after the packages   cjwInstallStarterContent(): class additions, the remote
//                        id references of the rich text, the links, the tags, the
//                        hidden configuration folder, the layouts of the starter
//                        (cjw-explayouts.json), and the file links removed again
//
// Every reference between objects, nodes, tags and layouts is made by remote id
// (cjw-starter-o-*, cjw-starter-n-*, cjw-starter-t-*), never by an id of another
// installation. Each step is idempotent and reports what it did to the debug log;
// a step that cannot do its work logs an error and the install carries on.

if ( !function_exists( 'cjwStarterContentPackage' ) )
{
    /** The content package of this site package (cjw_multisite_democontent), or false. */
    function cjwStarterContentPackage()
    {
        $package = eZPackage::fetch( cjwDemocontentPackageName(), false, false, false );
        return $package instanceof eZPackage ? $package : false;
    }

    /** A JSON file of the content package (cjw-starter-import.json, cjw-explayouts.json), decoded, or false. */
    function cjwStarterContentJSON( $name )
    {
        $package = cjwStarterContentPackage();
        $file = $package ? $package->path() . '/' . $name : '';
        if ( $file === '' || !is_file( $file ) )
        {
            eZDebug::writeError( "The content package has no $name", __FUNCTION__ );
            return false;
        }
        $data = json_decode( (string)file_get_contents( $file ), true );
        if ( !is_array( $data ) )
            eZDebug::writeError( "$name of the content package cannot be read", __FUNCTION__ );
        return is_array( $data ) ? $data : false;
    }

    /** The object files of the content package, in the order of its contentobjects.xml. */
    function cjwStarterObjectFiles()
    {
        $package = cjwStarterContentPackage();
        if ( !$package )
            return array();
        $dir = $package->path() . '/ezcontentobject';
        $list = new DOMDocument();
        if ( !@$list->load( $dir . '/contentobjects.xml' ) )
            return array();
        $files = array();
        foreach ( $list->getElementsByTagName( 'object-file' ) as $objectFile )
        {
            $file = $dir . '/' . $objectFile->getAttribute( 'filename' );
            if ( is_file( $file ) )
                $files[] = $file;
        }
        return $files;
    }

    /** Where the original of a simple file of the content package lives in the extension. */
    function cjwStarterFileSource( $extensionPath, $packagePath )
    {
        $root = eZSys::rootDir();
        if ( $extensionPath !== '' && is_file( $root . '/' . $extensionPath ) )
            return $root . '/' . $extensionPath;
        $name = basename( $packagePath );
        foreach ( array( 'content-images', 'content-files' ) as $dir )
        {
            $path = $root . '/' . eZExtension::baseDirectory() . '/cjw_themes_jumper/share/' . $dir . '/' . $name;
            if ( is_file( $path ) )
                return $path;
        }
        return false;
    }

    /**
     * The images and files of the starter content into the content package's simplefiles/, where the kernel's
     * package handler reads them (eZPackage::simpleFilePath()): a symbolic link to the original in the extension for
     * each file the package names (simple-file extension-path), a copy where links cannot be made. Nothing is
     * replaced that is there already. cjwUnlinkStarterFilesFromExtension() removes the links after the install.
     */
    function cjwLinkStarterFilesFromExtension()
    {
        $package = cjwStarterContentPackage();
        if ( !$package )
        {
            eZDebug::writeError( 'The content package ' . cjwDemocontentPackageName() . ' is not in the package repository', __FUNCTION__ );
            return false;
        }
        $dom = new DOMDocument();
        if ( !@$dom->load( $package->path() . '/package.xml' ) )
            return false;
        $linked = 0;
        $copied = 0;
        $missing = array();
        foreach ( $dom->getElementsByTagName( 'simple-file' ) as $simpleFile )
        {
            $packagePath = $simpleFile->getAttribute( 'package-path' );
            if ( $packagePath === '' )
                continue;
            $target = $package->path() . '/' . $packagePath;
            if ( file_exists( $target ) )
                continue;
            $source = cjwStarterFileSource( $simpleFile->getAttribute( 'extension-path' ), $packagePath );
            if ( $source === false )
            {
                $missing[] = basename( $packagePath );
                continue;
            }
            if ( !is_dir( dirname( $target ) ) )
                eZDir::mkdir( dirname( $target ), false, true );
            if ( @symlink( $source, $target ) )
                $linked++;
            else if ( @copy( $source, $target ) )
                $copied++;
            else
                $missing[] = basename( $packagePath );
        }
        if ( $missing )
            eZDebug::writeError( 'Files of the starter content not found in extension/cjw_themes_jumper/share: ' . implode( ' ', array_unique( $missing ) ), __FUNCTION__ );
        eZDebug::writeNotice( "Starter content files from the extension: $linked linked, $copied copied, " . count( $missing ) . ' missing', __FUNCTION__ );
        return !$missing;
    }

    /** The links cjwLinkStarterFilesFromExtension() made (copies are left; they are the files themselves). */
    function cjwUnlinkStarterFilesFromExtension()
    {
        $package = cjwStarterContentPackage();
        if ( !$package )
            return true;
        $removed = 0;
        foreach ( glob( $package->path() . '/simplefiles/*' ) ?: array() as $file )
        {
            if ( is_link( $file ) && strpos( (string)readlink( $file ), '/cjw_themes_jumper/share/' ) !== false && @unlink( $file ) )
                $removed++;
        }
        $dir = $package->path() . '/simplefiles';
        if ( is_dir( $dir ) && !glob( $dir . '/*' ) )
            @rmdir( $dir );
        eZDebug::writeNotice( "Removed $removed links to the extension's files from the content package", __FUNCTION__ );
        return true;
    }

    /**
     * The attributes cjw-class-additions.xml adds to the classes of sevenx_classes / the ng_* classes, each one only
     * when the class has no attribute of that identifier: never required, after the last attribute, with an empty value
     * in every existing object (as the class editor does). The class definitions of the content package carry them
     * already, so on a new installation everything is reported as present; an installation whose classes came from
     * elsewhere gets what is missing. Nothing is removed or changed on an existing attribute.
     */
    function cjwEnsureStarterClassAdditions()
    {
        $package = cjwStarterContentPackage();
        $file = $package ? $package->path() . '/cjw-class-additions.xml' : '';
        if ( $file === '' || !is_file( $file ) )
            return true;
        $add = new DOMDocument();
        if ( !@$add->load( $file ) )
            return false;
        $db = eZDB::instance();
        $added = 0;
        $present = 0;
        $failed = array();
        foreach ( $add->documentElement->getElementsByTagName( 'class' ) as $classNode )
        {
            $identifier = $classNode->getAttribute( 'identifier' );
            $class = eZContentClass::fetchByIdentifier( $identifier );
            if ( !$class )
            {
                $failed[] = "$identifier (no such class)";
                continue;
            }
            $newAttributes = array();
            foreach ( $classNode->getElementsByTagName( 'attribute' ) as $an )
            {
                $attrIdentifier = $an->getElementsByTagName( 'identifier' )->item( 0 )->textContent;
                $datatype = $an->getAttribute( 'datatype' );
                $existing = $class->fetchAttributeByIdentifier( $attrIdentifier );
                if ( $existing )
                {
                    if ( $existing->attribute( 'data_type_string' ) !== $datatype )
                        $failed[] = "$identifier.$attrIdentifier (" . $existing->attribute( 'data_type_string' ) . ", the package wants $datatype)";
                    else
                        $present++;
                    continue;
                }
                if ( !eZDataType::create( $datatype ) )
                {
                    $failed[] = "$identifier.$attrIdentifier (datatype $datatype is not active)";
                    continue;
                }
                $placement = 0;
                foreach ( $class->fetchAttributes() as $a )
                    $placement = max( $placement, (int)$a->attribute( 'placement' ) );
                $text = function ( $name ) use ( $an )
                {
                    $n = $an->getElementsByTagName( $name )->item( 0 );
                    return $n ? $n->textContent : '';
                };
                $names = new eZSerializedObjectNameList( $text( 'serialized-name-list' ) );
                $names->validate();
                $descriptions = new eZSerializedObjectNameList( $text( 'serialized-description-list' ) );
                $db->begin();
                $attribute = eZContentClassAttribute::create( $class->attribute( 'id' ), $datatype, array(
                    'version' => eZContentClass::VERSION_STATUS_DEFINED,
                    'identifier' => $attrIdentifier,
                    'serialized_name_list' => $names->serializeNames(),
                    'serialized_description_list' => $descriptions->serializeNames(),
                    'category' => $text( 'category' ),
                    'is_required' => 0,
                    'is_searchable' => strtolower( $an->getAttribute( 'searchable' ) ) == 'true' ? 1 : 0,
                    'is_information_collector' => 0,
                    'can_translate' => strtolower( $an->getAttribute( 'translatable' ) ) == 'false' ? 0 : 1,
                    'placement' => $placement + 1 ) );
                $attribute->store();
                $attribute->dataType()->unserializeContentClassAttribute( $attribute, $an, $an->getElementsByTagName( 'datatype-parameters' )->item( 0 ) );
                $attribute->store();
                $db->commit();
                $newAttributes[] = $attribute;
                $added++;
            }
            foreach ( $newAttributes as $attribute )
            {
                $db->begin();
                $attribute->initializeObjectAttributes();
                $db->commit();
            }
        }
        if ( $added )
            eZContentClass::expireCache();
        if ( $failed )
            eZDebug::writeError( 'Class additions of the starter content not applied: ' . implode( ', ', $failed ), __FUNCTION__ );
        eZDebug::writeNotice( "Class additions of the starter content: $added added, $present present", __FUNCTION__ );
        return !$failed;
    }

    /**
     * The references in the rich text of the starter objects (links and embeds by object and node remote id) are
     * turned into this installation's ids: eZContentObject::postUnserialize(), which the package handler skips for
     * objects whose remote id is not 32 characters long (it reads the remote id from the file name).
     */
    function cjwPostUnserializeStarterObjects()
    {
        $package = cjwStarterContentPackage();
        if ( !$package )
            return false;
        $done = 0;
        foreach ( cjwStarterObjectFiles() as $file )
        {
            if ( !preg_match( '/^object-(.+)\.xml$/', basename( $file ), $m ) )
                continue;
            $object = eZContentObject::fetchByRemoteID( $m[1] );
            if ( !$object )
                continue;
            $object->postUnserialize( $package );
            eZContentObject::clearCache( $object->attribute( 'id' ) );
            $done++;
        }
        eZDebug::writeNotice( "Rich text references resolved in $done starter objects", __FUNCTION__ );
        return true;
    }

    /** Link fields (ngenhancedlink) that name their target by remote id get the target's object id here. */
    function cjwResolveStarterLinks()
    {
        $db = eZDB::instance();
        $rows = $db->arrayQuery( "SELECT a.id, a.version FROM ezcontentobject_attribute a
                                  JOIN ezcontentobject o ON o.id = a.contentobject_id AND o.current_version = a.version
                                  WHERE a.data_type_string = 'ngenhancedlink' AND o.remote_id LIKE 'cjw-starter-o-%'" );
        $resolved = 0;
        $missing = array();
        foreach ( (array)$rows as $row )
        {
            $attribute = eZContentObjectAttribute::fetch( (int)$row['id'], (int)$row['version'] );
            if ( !$attribute )
                continue;
            $data = json_decode( (string)$attribute->attribute( 'data_text' ), true );
            if ( !is_array( $data ) || empty( $data['remote_id'] ) )
                continue;
            $target = eZContentObject::fetchByRemoteID( $data['remote_id'] );
            if ( !$target )
            {
                $missing[] = $data['remote_id'];
                continue;
            }
            if ( isset( $data['id'] ) && (int)$data['id'] === (int)$target->attribute( 'id' ) )
                continue;
            $data['id'] = (int)$target->attribute( 'id' );
            $attribute->dataType()->fromString( $attribute, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $attribute->store();
            $resolved++;
        }
        if ( $missing )
            eZDebug::writeError( 'Link targets of the starter content not installed: ' . implode( ', ', array_unique( $missing ) ), __FUNCTION__ );
        eZDebug::writeNotice( "Starter links resolved: $resolved", __FUNCTION__ );
        return true;
    }

    /**
     * The tags of the starter content: a tag "Starter" (remote id cjw-starter-t-root) with the content's tags below
     * it (cjw-starter-t-<id>, cjw-starter-import.json), each made once; then every eztags field of the starter
     * objects that names tags (attribute cjw-tag-remote-ids in the object file) is linked to them, per translation.
     * The tag ids in the object files are those of the source site and are not used.
     */
    function cjwFixStarterTags()
    {
        if ( !class_exists( 'eZTagsObject' ) )
        {
            eZDebug::writeError( 'eztags is not active, the starter tags are left out', __FUNCTION__ );
            return false;
        }
        $sidecar = cjwStarterContentJSON( 'cjw-starter-import.json' );
        if ( !$sidecar )
            return false;
        $db = eZDB::instance();
        $newTag = function ( $parent, $keyword, $locale, $remoteID ) use ( $db )
        {
            $language = eZContentLanguage::fetchByLocale( $locale );
            if ( !$language )
                $language = eZContentLanguage::topPriorityLanguage();
            if ( !$language )
                return false;
            $locale = $language->attribute( 'locale' );
            $db->begin();
            $tag = new eZTagsObject( array( 'parent_id' => $parent ? $parent->attribute( 'id' ) : 0, 'main_tag_id' => 0,
                                            'depth' => $parent ? $parent->attribute( 'depth' ) + 1 : 1,
                                            'path_string' => $parent ? $parent->attribute( 'path_string' ) : '/',
                                            'main_language_id' => $language->attribute( 'id' ),
                                            'language_mask' => eZContentLanguage::maskByLocale( array( $locale ), true ),
                                            'remote_id' => $remoteID ), $locale );
            $tag->store();
            $keywordObject = new eZTagsKeyword( array( 'keyword_id' => $tag->attribute( 'id' ), 'language_id' => $language->attribute( 'id' ) + 1,
                                                       'keyword' => $keyword, 'locale' => $locale, 'status' => eZTagsKeyword::STATUS_PUBLISHED ) );
            $keywordObject->store();
            $tag->setAttribute( 'path_string', $tag->attribute( 'path_string' ) . $tag->attribute( 'id' ) . '/' );
            $tag->store();
            $tag->updateModified();
            $db->commit();
            return eZTagsObject::fetch( $tag->attribute( 'id' ) );
        };
        $created = 0;
        $tagRoot = eZTagsObject::fetchByRemoteID( 'cjw-starter-t-root' );
        if ( !$tagRoot )
        {
            $tagRoot = $newTag( null, 'Starter', 'eng-US', 'cjw-starter-t-root' );
            $created++;
        }
        $tagMap = array();
        foreach ( (array)$sidecar['tags'] as $t )
        {
            $tag = eZTagsObject::fetchByRemoteID( $t['remote_id'] );
            if ( !$tag )
            {
                $parent = (int)$t['parent_id'] ? eZTagsObject::fetchByRemoteID( 'cjw-starter-t-' . (int)$t['parent_id'] ) : $tagRoot;
                $tag = $newTag( $parent, $t['translated'] ? $t['translated'] : $t['keyword'], $t['locale'] ? $t['locale'] : 'ger-DE', $t['remote_id'] );
                $created++;
            }
            if ( $tag )
                $tagMap[$t['remote_id']] = $tag;
        }

        $linked = 0;
        foreach ( cjwStarterObjectFiles() as $file )
        {
            $xml = (string)file_get_contents( $file );
            if ( strpos( $xml, 'cjw-tag-remote-ids=' ) === false )
                continue;
            $dom = new DOMDocument();
            if ( !@$dom->loadXML( $xml ) )
                continue;
            $object = eZContentObject::fetchByRemoteID( $dom->documentElement->getAttribute( 'remote_id' ) );
            if ( !$object )
                continue;
            $version = (int)$object->attribute( 'current_version' );
            foreach ( $dom->getElementsByTagNameNS( 'http://ez.no/object/', 'object-translation' ) as $translation )
            {
                $language = $translation->getAttribute( 'language' );
                $dataMap = $object->fetchDataMap( $version, $language );
                foreach ( $translation->getElementsByTagNameNS( 'http://ez.no/object/', 'attribute' ) as $attrNode )
                {
                    if ( $attrNode->getAttribute( 'type' ) !== 'eztags' || !$attrNode->hasAttribute( 'cjw-tag-remote-ids' ) )
                        continue;
                    $identifier = $attrNode->getAttributeNS( 'http://ez.no/ezobject', 'identifier' );
                    if ( !isset( $dataMap[$identifier] ) )
                        continue;
                    $ids = array();
                    $parents = array();
                    $keywords = array();
                    $locales = array();
                    foreach ( explode( ',', $attrNode->getAttribute( 'cjw-tag-remote-ids' ) ) as $tagRemoteID )
                    {
                        if ( !isset( $tagMap[$tagRemoteID] ) )
                            continue;
                        $tag = $tagMap[$tagRemoteID];
                        $ids[] = (int)$tag->attribute( 'id' );
                        $parents[] = (int)$tag->attribute( 'parent_id' );
                        $keywords[] = $tag->attribute( 'keyword' );
                        $locales[] = $language;
                    }
                    $attribute = $dataMap[$identifier];
                    $tags = eZTags::createFromStrings( $attribute, implode( '|#', $ids ), implode( '|#', $keywords ), implode( '|#', $parents ), implode( '|#', $locales ) );
                    $attribute->setContent( $tags );
                    $tags->store( $attribute );
                    $linked++;
                }
            }
            eZContentObject::clearCache( $object->attribute( 'id' ) );
        }
        eZDebug::writeNotice( "Starter tags: $created created, $linked fields linked", __FUNCTION__ );
        return true;
    }

    /**
     * Publish and modify times of the starter objects as the package carries them (ezremote:published / modified):
     * lists and galleries sorted by publication date come out as on the source site, not in install order. The same
     * as the import into the reference installation does; idempotent. Returns the number of objects set.
     */
    function cjwRestoreStarterDates()
    {
        $db = eZDB::instance();
        $set = 0;
        foreach ( cjwStarterObjectFiles() as $file )
        {
            $head = (string)file_get_contents( $file, false, null, 0, 2048 );
            if ( !preg_match( '#remote_id="(cjw-starter-o-[^"]+)"#', $head, $r ) || !preg_match( '#ezremote:published="([^"]+)"#', $head, $p ) ||
                 !preg_match( '#ezremote:modified="([^"]+)"#', $head, $m ) )
                continue;
            $object = eZContentObject::fetchByRemoteID( $r[1] );
            $published = (int)eZDateUtils::textToDate( $p[1] );
            $modified = (int)eZDateUtils::textToDate( $m[1] );
            if ( !$object || $published <= 0 )
                continue;
            if ( (int)$object->attribute( 'published' ) !== $published || (int)$object->attribute( 'modified' ) !== $modified )
            {
                $db->query( 'UPDATE ezcontentobject SET published = ' . $published . ', modified = ' . $modified . ' WHERE id = ' . (int)$object->attribute( 'id' ) );
                eZContentObject::clearCache( $object->attribute( 'id' ) );
                $set++;
            }
        }
        eZDebug::writeNotice( "Starter publish/modify times set from the package: $set", __FUNCTION__ );
        return true;
    }

    /** The configuration folder of the starter content (site info, cookie policy, header links) is hidden. */
    function cjwHideStarterNodes()
    {
        $sidecar = cjwStarterContentJSON( 'cjw-starter-import.json' );
        if ( !$sidecar )
            return false;
        foreach ( (array)$sidecar['hidden_node_remote_ids'] as $remoteID )
        {
            $node = eZContentObjectTreeNode::fetchByRemoteID( $remoteID );
            if ( !$node )
            {
                eZDebug::writeError( "Node $remoteID to hide is not installed", __FUNCTION__ );
                continue;
            }
            if ( !$node->attribute( 'is_hidden' ) )
                eZContentObjectTreeNode::hideSubTree( $node );
        }
        return true;
    }

    /**
     * The layouts of the starter (cjw-explayouts.json: layouts, zones, blocks, parameters, collections, items,
     * queries, rules, targets and conditions with ids from 900000 on, the same rows the reference installation
     * holds). Node references are named by remote id in the file (node_references) and set to this installation's
     * node ids; the rules' siteaccess condition names the siteaccesses of this installation. Rows of the band that
     * are there already are replaced, nothing below the band is touched.
     *
     * @param string[] $siteaccesses the user siteaccesses the starter layouts apply to (site and its translations)
     */
    function cjwSeedStarterLayouts( array $siteaccesses )
    {
        $seed = cjwStarterContentJSON( 'cjw-explayouts.json' );
        if ( !$seed || empty( $seed['rows'] ) )
            return false;
        $band = isset( $seed['band'] ) ? (int)$seed['band'] : 900000;
        $db = eZDB::instance();

        $nodeIDs = array();
        foreach ( (array)$db->arrayQuery( "SELECT node_id, remote_id FROM ezcontentobject_tree WHERE remote_id LIKE 'cjw-starter-n-%'" ) as $r )
            $nodeIDs[$r['remote_id']] = (int)$r['node_id'];

        $rows = $seed['rows'];
        $references = isset( $seed['node_references'] ) ? $seed['node_references'] : array();
        $unresolved = array();
        foreach ( $references as $table => $byID )
        {
            if ( !isset( $rows[$table] ) )
                continue;
            foreach ( $rows[$table] as $i => $row )
            {
                if ( !isset( $byID[(string)$row['id']] ) )
                    continue;
                foreach ( $byID[(string)$row['id']] as $field => $remoteID )
                {
                    if ( !isset( $nodeIDs[$remoteID] ) )
                    {
                        $unresolved[] = "$table {$row['id']} $field $remoteID";
                        continue;
                    }
                    if ( strpos( $field, 'parameters.' ) === 0 )
                    {
                        $parameters = json_decode( (string)$row['parameters'], true );
                        if ( !is_array( $parameters ) )
                            continue;
                        $parameters[substr( $field, 11 )] = $nodeIDs[$remoteID];
                        $rows[$table][$i]['parameters'] = json_encode( $parameters );
                    }
                    else
                    {
                        $rows[$table][$i][$field] = is_int( $row[$field] ) ? $nodeIDs[$remoteID] : (string)$nodeIDs[$remoteID];
                    }
                }
            }
        }
        if ( isset( $rows['explayouts_rule_condition'] ) )
        {
            foreach ( $rows['explayouts_rule_condition'] as $i => $row )
                if ( $row['condition_type'] === 'siteaccess' )
                    $rows['explayouts_rule_condition'][$i]['condition_value'] = json_encode( array_values( $siteaccesses ) );
        }
        if ( $unresolved )
        {
            eZDebug::writeError( 'Starter layout references to nodes that are not installed: ' . implode( '; ', $unresolved ), __FUNCTION__ );
            return false;
        }

        $db->begin();
        foreach ( array_keys( $rows ) as $table )
            $db->query( "DELETE FROM $table WHERE id >= $band" );
        $inserted = 0;
        foreach ( $rows as $table => $list )
        {
            foreach ( $list as $row )
            {
                $columns = implode( ', ', array_keys( $row ) );
                $values = implode( ', ', array_map( function ( $v ) use ( $db )
                {
                    if ( is_int( $v ) )
                        return (string)$v;
                    if ( $v === null )
                        return 'NULL';
                    return "'" . $db->escapeString( (string)$v ) . "'";
                }, array_values( $row ) ) );
                if ( !$db->query( "INSERT INTO $table ( $columns ) VALUES ( $values )" ) )
                {
                    $db->rollback();
                    eZDebug::writeError( "The starter layouts could not be written ($table): " . $db->errorMessage(), __FUNCTION__ );
                    return false;
                }
                $inserted++;
            }
        }
        $db->commit();
        if ( method_exists( $db, 'correctSequenceValues' ) )
            $db->correctSequenceValues();
        if ( class_exists( 'expLayoutsResolver' ) )
            expLayoutsResolver::clearCache();
        eZDebug::writeNotice( "Starter layouts: $inserted rows in " . count( $rows ) . ' tables, rules for ' . implode( ', ', $siteaccesses ), __FUNCTION__ );
        return true;
    }

    /**
     * The URL paths of the starter (cjw-paths.json of the content package: the path every node has on the source
     * site, per language view "ger" / "eng"), as the import into the reference installation sets them
     * (cjw_starter_import_fix_paths.php): top-down, per node and language, the node's own alias element is compared
     * with the wanted last segment;
     *   - a class building its URL from url_text: the package carries the wanted text, the alias is regenerated
     *     (updateSubTreePath), which also lets a sibling take back a freed name;
     *   - otherwise (title-based classes): the element is renamed with eZURLAliasML::storePath, as a rename on
     *     publish does: the new text is the original, the old one a history entry that redirects (301) to it.
     * A swap moves the holder of the wanted name to a temporary name first (its history row is removed at the end);
     * up to three passes. Returns the number of differences left.
     */
    function cjwApplyStarterPaths()
    {
        $paths = cjwStarterContentJSON( 'cjw-paths.json' );
        if ( !$paths )
            return false;
        $db = eZDB::instance();
        $elements = function ( $nodeID ) use ( $db )
        {
            $out = array();
            foreach ( (array)$db->arrayQuery( "SELECT id, parent, text, lang_mask FROM ezurlalias_ml WHERE action = 'eznode:" . (int)$nodeID . "' AND is_original = 1 AND is_alias = 0" ) as $r )
                foreach ( eZContentLanguage::fetchList() as $l )
                    if ( (int)$r['lang_mask'] & (int)$l->attribute( 'id' ) )
                        $out[$l->attribute( 'locale' )] = $r;
            return $out;
        };
        $lastSegment = function ( $path ) { return $path === '' ? '' : substr( strrchr( '/' . $path, '/' ), 1 ); };
        $want = function ( $remoteID, $locale ) use ( $paths, $lastSegment )
        {
            $view = $locale === 'ger-DE' ? 'ger' : 'eng';
            return isset( $paths[$remoteID][$view] ) ? $lastSegment( (string)$paths[$remoteID][$view] ) : '';
        };
        $rows = $db->arrayQuery( "SELECT node_id, remote_id, is_invisible FROM ezcontentobject_tree WHERE remote_id LIKE 'cjw-starter-n-%' AND remote_id <> 'cjw-starter-n-root' ORDER BY depth, node_id" );
        $changes = 0;
        $before = array_map( function ( $l ) { return $l->attribute( 'locale' ); }, (array)eZContentLanguage::prioritizedLanguages() );
        $allLocales = array_map( function ( $l ) { return $l->attribute( 'locale' ); }, (array)eZContentLanguage::fetchList() );
        for ( $pass = 1; $pass <= 3; $pass++ )
        {
            $todo = 0;
            foreach ( $rows as $r )
            {
                if ( $r['is_invisible'] || !isset( $paths[$r['remote_id']] ) )
                    continue;
                $node = eZContentObjectTreeNode::fetch( (int)$r['node_id'] );
                $object = $node ? $node->object() : null;
                if ( !$object )
                    continue;
                $usesUrlText = strpos( (string)$object->contentClass()->attribute( 'url_alias_name' ), '<url_text' ) === 0;
                $have = $elements( $r['node_id'] );
                foreach ( $object->availableLanguages() as $locale )
                {
                    $text = $want( $r['remote_id'], $locale );
                    $current = isset( $have[$locale] ) ? $have[$locale]['text'] : '';
                    if ( $text === '' || strcasecmp( $text, $current ) === 0 )
                        continue;
                    $todo++;
                    $changes++;
                    $parentElementID = isset( $have[$locale] ) ? (int)$have[$locale]['parent'] : 0;
                    $language = eZContentLanguage::fetchByLocale( $locale );
                    // the alias code reads the prioritised languages: this language first, as the reference installation
                    // ran the step from the siteaccess of that language
                    eZContentLanguage::setPrioritizedLanguages( array_values( array_unique( array_merge( array( $locale ), $allLocales ) ) ) );
                    eZURLAliasML::$PathByActionListCache = array();
                    $db->begin();
                    $holder = $db->arrayQuery( "SELECT action FROM ezurlalias_ml WHERE parent = $parentElementID AND text_md5 = '" . md5( strtolower( $text ) ) .
                                               "' AND is_original = 1 AND is_alias = 0 AND action <> 'eznode:" . (int)$r['node_id'] . "'" );
                    if ( $holder && preg_match( '#^eznode:(\d+)$#', $holder[0]['action'], $hm ) )
                    {
                        $other = eZContentObject::fetchByNodeID( (int)$hm[1] );
                        eZURLAliasML::storePath( $text . '-cjw-swap', $holder[0]['action'], $language, false,
                                                 $other ? (int)$other->attribute( 'language_mask' ) & 1 : 0, $parentElementID, false );
                    }
                    // The wanted text may remain as a history entry of the node that held it (a swap, or an
                    // earlier rename): storePath() refuses a text that names another node, so that entry goes.
                    $db->query( "DELETE FROM ezurlalias_ml WHERE parent = $parentElementID AND text_md5 = '" . md5( strtolower( $text ) ) .
                                "' AND is_original = 0 AND action <> 'eznode:" . (int)$r['node_id'] . "'" );
                    if ( $usesUrlText )
                        $node->updateSubTreePath();
                    else
                        eZURLAliasML::storePath( $text, 'eznode:' . (int)$r['node_id'], $language, false,
                                                 (int)$object->attribute( 'language_mask' ) & 1, $parentElementID, false );
                    $db->commit();
                    $have = $elements( $r['node_id'] );
                }
            }
            if ( !$todo )
                break;
        }
        // the temporary names of a swap are history entries only; nothing links to them
        foreach ( (array)$db->arrayQuery( "SELECT id, parent, text_md5, action FROM ezurlalias_ml WHERE is_original = 0 AND is_alias = 0 AND text LIKE '%-cjw-swap'" ) as $r )
            $db->query( "DELETE FROM ezurlalias_ml WHERE parent = " . (int)$r['parent'] . " AND text_md5 = '" . $db->escapeString( $r['text_md5'] ) . "'" );
        $left = 0;
        foreach ( $rows as $r )
        {
            if ( $r['is_invisible'] || !isset( $paths[$r['remote_id']] ) )
                continue;
            $object = eZContentObject::fetchByNodeID( (int)$r['node_id'] );
            $have = $elements( $r['node_id'] );
            foreach ( $object ? $object->availableLanguages() : array() as $locale )
            {
                $text = $want( $r['remote_id'], $locale );
                if ( $text !== '' && strcasecmp( $text, isset( $have[$locale] ) ? $have[$locale]['text'] : '' ) !== 0 )
                {
                    $left++;
                    eZDebug::writeError( "Path of {$r['remote_id']} ($locale) is not $text", __FUNCTION__ );
                }
            }
        }
        eZURLAliasML::$PathByActionListCache = array();
        if ( $before )
            eZContentLanguage::setPrioritizedLanguages( $before );
        eZDebug::writeNotice( "Starter paths: $changes renamed, $left differences left", __FUNCTION__ );
        return $left === 0;
    }

    /**
     * The privacy and cookie policy page of the user siteaccesses (menu.ini [SiteInfo] PrivacyPolicyID and
     * CookiePolicyID: the cookie banner and the video player link it): the starter's privacy page
     * ("Datenschutzerklärung", node remote id cjw-starter-n-809), as on the reference installation's starter.
     *
     * @param string[] $siteaccesses
     */
    function cjwSetStarterPolicyPages( array $siteaccesses )
    {
        $node = eZContentObjectTreeNode::fetchByRemoteID( 'cjw-starter-n-809' );
        if ( !$node )
        {
            eZDebug::writeError( 'The privacy page cjw-starter-n-809 is not installed', __FUNCTION__ );
            return false;
        }
        foreach ( $siteaccesses as $siteaccess )
        {
            $dir = 'settings/siteaccess/' . $siteaccess;
            if ( !is_dir( $dir ) )
                continue;
            $ini = eZINI::instance( 'menu.ini.append.php', $dir, null, false, null, true );
            $ini->setReadOnlySettingsCheck( false );
            $ini->setVariable( 'SiteInfo', 'PrivacyPolicyID', (int)$node->attribute( 'node_id' ) );
            $ini->setVariable( 'SiteInfo', 'CookiePolicyID', (int)$node->attribute( 'node_id' ) );
            $ini->save( false, false, false, false, true, true );
        }
        eZDebug::writeNotice( 'Privacy and cookie policy page: node ' . $node->attribute( 'node_id' ) . ' on ' . implode( ', ', $siteaccesses ), __FUNCTION__ );
        return true;
    }

    /**
     * Template overrides another active extension ships for a siteaccess of this name
     * (extension/<ext>/settings/siteaccess/<name>/override.ini.append.php: the media design's for "site") name
     * templates the design chain of the starter does not have. Placed before the design's own, an override whose
     * template is not found renders an empty page, so each of those override blocks is taken out of use in the
     * siteaccess's own override.ini: its Source becomes a template nobody asks for, and its MatchFile one that
     * exists. cjw_themes_jumper's own overrides (settings/siteaccess/cjw_starter) are not affected.
     *
     * @return string[] the names of the override blocks taken out of use
     */
    function cjwOverrideBlocksOfOtherExtensions( $siteaccess, array $extensions )
    {
        $names = array();
        foreach ( $extensions as $extension )
        {
            if ( $extension === 'cjw_themes_jumper' )
                continue;
            foreach ( array( 'override.ini.append.php', 'override.ini.append', 'override.ini' ) as $file )
            {
                $path = eZExtension::baseDirectory() . "/$extension/settings/siteaccess/$siteaccess/$file";
                if ( !is_file( $path ) )
                    continue;
                if ( preg_match_all( '/^\s*\[([^\]\r\n]+)\]\s*$/m', (string)file_get_contents( $path ), $m ) )
                    foreach ( $m[1] as $name )
                        $names[trim( $name )] = $extension;
            }
        }
        return $names;
    }

    /** Writes settings/siteaccess/<name>/override.ini.append.php for the starter (see cjwOverrideBlocksOfOtherExtensions()). */
    function cjwWriteStarterSiteaccessOverrides( $siteaccess, array $extensions )
    {
        $blocks = cjwOverrideBlocksOfOtherExtensions( $siteaccess, $extensions );
        $lines = array(
            '<?php /* #?ini charset="utf-8"?',
            '',
            '# The starter design (cjw_themes_jumper) brings its own overrides in settings/siteaccess/cjw_starter, taken in',
            '# through ExtensionSettingsSiteAccess=cjw_starter. The override blocks below come from other extensions\'',
            '# settings for a siteaccess of this name and name templates the starter -> standard design chain does not',
            '# have; they are taken out of use: Source is a template nobody asks for.',
        );
        foreach ( $blocks as $name => $extension )
        {
            $lines[] = '';
            $lines[] = "# from $extension";
            $lines[] = "[$name]";
            $lines[] = 'Source=cjw/override_not_used_by_the_starter_design.tpl';
            $lines[] = 'MatchFile=pdf_category.tpl';
            $lines[] = 'Subdir=templates';
        }
        $lines[] = '';
        $lines[] = '*/ ?>';
        $dir = 'settings/siteaccess/' . $siteaccess;
        if ( !is_dir( $dir ) )
            eZDir::mkdir( $dir, false, true );
        eZFile::create( 'override.ini.append.php', $dir, implode( "\n", $lines ) . "\n" );
        eZDebug::writeNotice( count( $blocks ) . " override blocks of other extensions taken out of use in $dir", __FUNCTION__ );
        return true;
    }

    /**
     * Everything the starter content needs after the kernel installed its objects. Each part logs its own result;
     * the install carries on when one fails (see the debug log).
     *
     * @param string[] $siteaccesses the user siteaccesses (the starter layouts' rules apply to them)
     */
    function cjwInstallStarterContent( array $siteaccesses )
    {
        $admin = eZUser::fetch( 14 );
        if ( $admin )
            eZUser::setCurrentlyLoggedInUser( $admin, 14 );
        $result = array();
        $result['class additions'] = cjwEnsureStarterClassAdditions();
        $result['rich text references'] = cjwPostUnserializeStarterObjects();
        $result['links'] = cjwResolveStarterLinks();
        $result['tags'] = cjwFixStarterTags();
        $result['dates'] = cjwRestoreStarterDates();
        $result['hidden folder'] = cjwHideStarterNodes();
        $result['layouts'] = cjwSeedStarterLayouts( $siteaccesses );
        $result['file links removed'] = cjwUnlinkStarterFilesFromExtension();
        $failed = array_keys( array_filter( $result, function ( $ok ) { return !$ok; } ) );
        if ( $failed )
            eZDebug::writeError( 'Starter content steps that failed: ' . implode( ', ', $failed ), __FUNCTION__ );
        eZContentCacheManager::clearAllContentCache();
        return true;
    }
}
