<?php

/*
 * The MIT License
 *
 * Copyright (c) 2012 Allan Sun <sunajia@gmail.com>
 * Copyright (c) 2012-2020 Toha <tohenk@yahoo.com>
 * Copyright (c) 2013 WitteStier <development@wittestier.nl>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace MwbExporter\Formatter\Node\Sequelize6\Model;

use MwbExporter\Model\Table as BaseTable;
use MwbExporter\Formatter\Node\Sequelize6\Formatter;
use MwbExporter\Writer\WriterInterface;
use MwbExporter\Object\JS;
use MwbExporter\Helper\Comment;
use MwbExporter\Formatter\DatatypeConverterInterface;

class Table extends BaseTable
{
    /**
     * Get JSObject.
     *
     * @param mixed $content    Object content
     * @param bool  $multiline  Multiline result
     * @param bool  $raw        Is raw object
     * @return \MwbExporter\Object\JS
     */
    public function getJSObject($content, $multiline = true, $raw = false)
    {
        $indentation = $this->getConfig()->get(Formatter::CFG_USE_TABS) ? "\t" : ' ';
        $indentation = str_repeat($indentation, $this->getConfig()->get(Formatter::CFG_INDENTATION));

        return new JS($content, array(
            'multiline' => $multiline,
            'raw' => $raw,
            'indentation' => $indentation,
        ));
    }

    public function writeTable(WriterInterface $writer)
    {
        switch (true) {
            case $this->isExternal():
                return self::WRITE_EXTERNAL;

            case $this->getConfig()->get(Formatter::CFG_SKIP_M2M_TABLES) && $this->isManyToMany():
                return self::WRITE_M2M;

            default:
                $writer->open($this->getTableFileName());
                $this->writeBody($writer);
                $writer->close();
                return self::WRITE_OK;
        }
    }

    /**
     * Write model body code.
     *
     * @param \MwbExporter\Writer\WriterInterface $writer
     * @return \MwbExporter\Formatter\Node\Sequelize6\Model\Table
     */
    protected function writeBody(WriterInterface $writer)
    {
        $writer
            ->writeCallback(function(WriterInterface $writer, Table $_this = null) {
                if ($_this->getConfig()->get(Formatter::CFG_ADD_COMMENT)) {
                    $writer
                        ->write($_this->getFormatter()->getComment(Comment::FORMAT_JS))
                        ->write('')
                    ;
                }
            })
            ->write("const { DataTypes, Model } = require('sequelize');")
            ->write("")
            ->write("class %s extends Model {", $this->getModelName())
            ->write("}")
            ->write("")
            ->write("module.exports = (sequelize) => {")
            ->indent()
                ->write("return %s.init(%s, %s);", $this->getModelName(), $this->asModel(), $this->asOptions())
            ->outdent()
            ->write("}")
        ;

        return $this;
    }

    protected function asOptions()
    {
        $result = array(
            'sequelize' => $this->getJSObject('sequelize', false, true),
            'modelName' => $this->getModelName(),
            'tableName' => $this->getRawTableName(),
            'indexes' => count($indexes = $this->getIndexes()) ? $indexes : null,
            'timestamps' => false,
            'underscored' => true,
            'syncOnAssociation' => false
        );

        return $this->getJSObject($result);
    }

    protected function asModel()
    {
        $result = $this->getFields();

        return $this->getJSObject($result);
    }

    /**
     * Get model fields.
     *
     * @return array
     */
    protected function getFields()
    {
        $result = array();
        /** @var \MwbExporter\Model\Column $column */
        foreach ($this->getColumns() as $column)
        {
            $type = $this->getFormatter()->getDatatypeConverter()->getType($column);
            if (DatatypeConverterInterface::DATATYPE_DECIMAL == $column->getColumnType()) {
                $type .= sprintf('(%s, %s)', $column->getParameters()->get('precision'), $column->getParameters()->get('scale'));
            } elseif (($len = $column->getLength()) > 0) {
                $type .= sprintf('(%s)', $len);
            }
            $c = array();
            $c['type'] = $this->getJSObject(sprintf('DataTypes.%s', $type ? $type : 'STRING.BINARY'), true, true);
            if ($column->isPrimary()) {
                $c['primaryKey'] = true;
            }
            if ($column->isAutoIncrement()) {
                $c['autoIncrement'] = true;
            } elseif ($column->isNotNull()) {
                $c['allowNull'] = false;
            }
            if (count($column->getForeignKeys())) {
                $c['references'] = array();
                /** @var \MwbExporter\Model\ForeignKey $foreignKey */
                foreach ($column->getForeignKeys() as $foreignKey) {
                    $c['references']['model'] = $foreignKey->getReferencedTable()->getModelName();
                    $c['references']['key'] = implode(';', $foreignKey->getForeignColumns());
                    if ($onUpdate = $foreignKey->getParameter('updateRule'))
                    {
                        $c['onUpdate'] = strtoupper($onUpdate);
                    }
                    if ($onDelete = $foreignKey->getParameter('deleteRule'))
                    {
                        $c['onDelete'] = strtoupper($onDelete);
                    }
                }
            }
            $result[$column->getColumnName()] = $c;
        }

        return $result;
    }

    protected function getIndexes()
    {
        $result = array();
        foreach ($this->getIndices() as $index) {
            if ($index->isIndex() || $index->isUnique()) {
                $result[] = array(
                    'name' => $index->getName(),
                    'fields' => $this->getJSObject($index->getColumnNames(), false),
                    'unique' => $index->isUnique() ? true : null,
                );
            }
        }

        return $result;
    }
}